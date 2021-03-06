<?php

namespace Oro\Bundle\WorkflowBundle\Datagrid;

use Doctrine\Common\Collections\ArrayCollection;

use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Datagrid\DatagridInterface;
use Oro\Bundle\DataGridBundle\Datasource\Orm\OrmDatasource;
use Oro\Bundle\DataGridBundle\Datasource\ResultRecord;
use Oro\Bundle\DataGridBundle\Event\BuildAfter;
use Oro\Bundle\DataGridBundle\Event\BuildBefore;
use Oro\Bundle\DataGridBundle\Event\OrmResultAfter;
use Oro\Bundle\DataGridBundle\EventListener\AbstractDatagridListener;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;

use Oro\Bundle\WorkflowBundle\Entity\Repository\WorkflowItemRepository;
use Oro\Bundle\WorkflowBundle\Form\Type\WorkflowDefinitionSelectType;
use Oro\Bundle\WorkflowBundle\Form\Type\WorkflowStepSelectType;
use Oro\Bundle\WorkflowBundle\Helper\WorkflowQueryTrait;
use Oro\Bundle\WorkflowBundle\Model\WorkflowRegistry;

class WorkflowStepColumnListener extends AbstractDatagridListener
{
    use WorkflowQueryTrait;

    const WORKFLOW_STEP_COLUMN = 'workflowStepLabel';

    const WORKFLOW_FILTER = 'workflowStepLabelByWorkflow';
    const WORKFLOW_STEP_FILTER = 'workflowStepLabelByWorkflowStep';

    /**
     * @var ConfigProvider
     */
    protected $configProvider;

    /**
     * @var WorkflowRegistry
     */
    protected $workflowRegistry;

    /**
     * @var array
     */
    protected $workflowStepColumns = [self::WORKFLOW_STEP_COLUMN];

    /**
     * @var ArrayCollection[] key(Entity Class) => value(ArrayCollection of Workflow instances)
     */
    protected $workflows = [];

    /**
     * @param DoctrineHelper  $doctrineHelper
     * @param ConfigProvider  $configProvider
     * @param WorkflowRegistry $workflowRegistry
     */
    public function __construct(
        DoctrineHelper $doctrineHelper,
        ConfigProvider $configProvider,
        WorkflowRegistry $workflowRegistry
    ) {
        parent::__construct($doctrineHelper);
        $this->configProvider = $configProvider;
        $this->workflowRegistry = $workflowRegistry;
    }

    /**
     * @param string $columnName
     */
    public function addWorkflowStepColumn($columnName)
    {
        if (!in_array($columnName, $this->workflowStepColumns, true)) {
            $this->workflowStepColumns[] = $columnName;
        }
    }

    /**
     * @param BuildBefore $event
     */
    public function onBuildBefore(BuildBefore $event)
    {
        $config = $event->getConfig();

        // datasource type other than ORM is not supported yet
        if ($config->getDatasourceType() !== OrmDatasource::TYPE) {
            return;
        }

        // get root entity
        list($rootEntity, $rootEntityAlias) = $this->getRootEntityNameAndAlias($config);

        if (!$rootEntity || !$rootEntityAlias) {
            return;
        }

        // whether entity has active workflow and entity should render workflow step field
        $isShowWorkflowStep = $this->getWorkflows($rootEntity)->isEmpty() === false
            && $this->isShowWorkflowStep($rootEntity);

        // check whether grid contains workflow step column
        $columns = $config->offsetGetByPath('[columns]', []);
        $workflowStepColumns = array_intersect($this->workflowStepColumns, array_keys($columns));

        // remove workflow step if it must be hidden but there are workflow step columns
        if (!$isShowWorkflowStep && $workflowStepColumns) {
            $this->removeWorkflowStep($config, $workflowStepColumns);
        }

        // add workflow step if it must be shown and there are no workflow step columns
        if ($isShowWorkflowStep && !$workflowStepColumns) {
            $this->addWorkflowStep($config, $rootEntity, $rootEntityAlias);
        }
    }

    /**
     * @param BuildAfter $event
     */
    public function onBuildAfter(BuildAfter $event)
    {
        $datagrid = $event->getDatagrid();

        $config = $datagrid->getConfig();
        $datasource = $datagrid->getDatasource();

        if (!($datasource instanceof OrmDatasource) || !$this->isApplicable($config)) {
            return;
        }

        $this->applyFilter($datagrid, self::WORKFLOW_FILTER, 'getEntityIdsByEntityClassAndWorkflowNames');
        $this->applyFilter($datagrid, self::WORKFLOW_STEP_FILTER, 'getEntityIdsByEntityClassAndWorkflowStepIds');
    }

    /**
     * @param OrmResultAfter $event
     */
    public function onResultAfter(OrmResultAfter $event)
    {
        $config = $event->getDatagrid()->getConfig();

        if (!$this->isApplicable($config)) {
            return;
        }

        // get root entity
        list($rootEntity) = $this->getRootEntityNameAndAlias($config);

        /** @var ResultRecord[] $records */
        $records = $event->getRecords();

        $workflowItems = $this->getWorkflowItemRepository()->getGroupedWorkflowNameAndWorkflowStepName(
            $rootEntity,
            array_map(
                function (ResultRecord $record) {
                    return $record->getValue('id');
                },
                $records
            ),
            $this->isEntityHaveMoreThanOneWorkflow($rootEntity)
        );

        foreach ($records as $record) {
            $items = [];

            $id = $record->getValue('id');
            if (array_key_exists($id, $workflowItems)) {
                foreach ($workflowItems[$id] as $data) {
                    $items[] = $data;
                }
            }

            $record->addData([self::WORKFLOW_STEP_COLUMN => $items]);
        }
    }

    /**
     * @param string $entity
     * @return bool
     */
    protected function isShowWorkflowStep($entity)
    {
        if ($this->configProvider->hasConfig($entity)) {
            return $this->configProvider
                ->getConfig($entity)
                ->is('show_step_in_grid');
        }

        return false;
    }

    /**
     * @param DatagridConfiguration $config
     * @param string $rootEntity
     * @param string $rootEntityAlias
     */
    protected function addWorkflowStep(DatagridConfiguration $config, $rootEntity, $rootEntityAlias)
    {
        // add column
        $columns = $config->offsetGetByPath('[columns]', []);
        $columns[self::WORKFLOW_STEP_COLUMN] = [
            'label' => 'oro.workflow.workflowstep.grid.label',
            'type' => 'twig',
            'frontend_type' => 'html',
            'template' => 'OroWorkflowBundle:Datagrid:Column/workflowStep.html.twig'
        ];
        $config->offsetSetByPath('[columns]', $columns);

        $isManyWorkflows = $this->isEntityHaveMoreThanOneWorkflow($rootEntity);
        if (!$isManyWorkflows) {
            $config->offsetSetByPath(
                '[source][query]',
                $this->addDatagridQuery(
                    $config->offsetGetByPath('[source][query]', []),
                    $rootEntityAlias,
                    $rootEntity,
                    'id',
                    self::WORKFLOW_STEP_COLUMN
                )
            );
        }

        // add filter (only if there is at least one filter)
        $filters = $config->offsetGetByPath('[filters][columns]', []);
        if ($filters) {
            if ($isManyWorkflows) {
                $filters[self::WORKFLOW_FILTER] = [
                    'label' => 'oro.workflow.workflowdefinition.entity_label',
                    'type' => 'entity',
                    'data_name' => self::WORKFLOW_STEP_COLUMN,
                    'options' => [
                        'field_type' => WorkflowDefinitionSelectType::NAME,
                        'field_options' => [
                            'workflow_entity_class' => $rootEntity,
                            'multiple' => true
                        ]
                    ]
                ];
            }

            $filters[self::WORKFLOW_STEP_FILTER] = [
                'label' => 'oro.workflow.workflowstep.grid.label',
                'type' => 'entity',
                'data_name' => self::WORKFLOW_STEP_COLUMN . '.id',
                'options' => [
                    'field_type' => WorkflowStepSelectType::NAME,
                    'field_options' => [
                        'workflow_entity_class' => $rootEntity,
                        'multiple' => true
                    ]
                ]
            ];
            $config->offsetSetByPath('[filters][columns]', $filters);
        }

        // add sorter (only if there is at least one sorter)
        $sorters = $config->offsetGetByPath('[sorters][columns]', []);
        if ($sorters && !$isManyWorkflows) {
            $sorters[self::WORKFLOW_STEP_COLUMN] = ['data_name' => self::WORKFLOW_STEP_COLUMN . '.stepOrder'];
            $config->offsetSetByPath('[sorters][columns]', $sorters);
        }
    }

    /**
     * @param DatagridConfiguration $config
     * @param array $workflowStepColumns
     */
    protected function removeWorkflowStep(DatagridConfiguration $config, array $workflowStepColumns)
    {
        $paths = [
            '[columns]',
            '[filters][columns]',
            '[sorters][columns]'
        ];

        foreach ($paths as $path) {
            $columns = $config->offsetGetByPath($path, []);
            foreach ($workflowStepColumns as $column) {
                if (!empty($columns[$column])) {
                    unset($columns[$column]);
                }
            }
            $config->offsetSetByPath($path, $columns);
        }
    }

    /**
     * Check whether grid contains workflow step column
     *
     * @param DatagridConfiguration $config
     * @return bool
     */
    protected function isApplicable(DatagridConfiguration $config)
    {
        $columns = $config->offsetGetByPath('[columns]', []);

        return count(array_intersect($this->workflowStepColumns, array_keys($columns))) > 0;
    }

    /**
     * @return WorkflowItemRepository
     */
    protected function getWorkflowItemRepository()
    {
        return $this->doctrineHelper->getEntityRepository('OroWorkflowBundle:WorkflowItem');
    }

    /**
     * @param DatagridInterface $datagrid
     * @param string $filter
     * @param string $repositoryMethod
     */
    protected function applyFilter(DatagridInterface $datagrid, $filter, $repositoryMethod)
    {
        $parameters = $datagrid->getParameters();
        $filters = $parameters->get('_filter', []);

        if (array_key_exists($filter, $filters) && array_key_exists('value', $filters[$filter])) {
            list($rootEntity, $rootEntityAlias) = $this->getRootEntityNameAndAlias($datagrid->getConfig());

            $items = $this->getWorkflowItemRepository()
                ->$repositoryMethod($rootEntity, (array)$filters[$filter]['value']);

            /** @var OrmDatasource $datasource */
            $datasource = $datagrid->getDatasource();

            $qb = $datasource->getQueryBuilder();
            $param = $qb->getParameter('filteredWorkflowItemIds');

            if ($param === null) {
                $qb->andWhere($qb->expr()->in($rootEntityAlias, ':filteredWorkflowItemIds'))
                    ->setParameter('filteredWorkflowItemIds', $items);
            } else {
                $qb->setParameter('filteredWorkflowItemIds', array_intersect((array)$param->getValue(), $items));
            }

            unset($filters[$filter]);
            $parameters->set('_filter', $filters);
        }
    }

    /**
     * @param string $className
     * @return ArrayCollection
     */
    protected function getWorkflows($className)
    {
        if (!array_key_exists($className, $this->workflows)) {
            $this->workflows[$className] = $this->workflowRegistry->getActiveWorkflowsByEntityClass($className);
        }

        return $this->workflows[$className];
    }

    /**
     * @param string $className
     * @return bool
     */
    protected function isEntityHaveMoreThanOneWorkflow($className)
    {
        return $this->getWorkflows($className)->count() > 1;
    }
}
