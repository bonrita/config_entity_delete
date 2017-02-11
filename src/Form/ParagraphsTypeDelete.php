<?php
/**
 * @file
 * Contains functionality to delete paragraph items in the system.
 * This goes for a paragraph that needs to be deleted.
 */

namespace Drupal\paragraph_delete\Form;


use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\Cache;

/**
 * Class ParagraphsTypeDelete
 *   Form to show the content that houses paragraph items in the system.
 *
 * @package Drupal\paragraph_delete\Form
 */
class ParagraphsTypeDelete extends FormBase {

  /**
   * Paragraph content entity type base table.
   *
   * @var string
   */
   const PARAGRAPHS_TABLE = 'paragraphs_item';

  /**
   * The query factory to create entity queries.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $queryFactory;

  /**
   * The current primary database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /** @var \Drupal\paragraphs\Entity\ParagraphsType $paragraphs_type */
  protected $paragraphsType;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * ParagraphsTypeDelete constructor.
   *
   * @param \Drupal\Core\Entity\Query\QueryFactory $query_factory
   *   The entity query object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(QueryFactory $query_factory, Connection $connection, ModuleHandlerInterface $module_handler) {
    $this->queryFactory = $query_factory;
    $this->connection = $connection;
    $this->moduleHandler = $module_handler;
    $request = $this->getRequest();
    $this->paragraphsType = $request->attributes->get('paragraph_type', FALSE);
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.query'),
      $container->get('database'),
      $container->get('module_handler')
    );
  }

  /**
   * @inheritDoc
   */
  public function getFormId() {
    return 'paragraph_type_delete';
  }

  /**
   * @inheritDoc
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $cache = new CacheableMetadata();

    $rows = $this->getParagraphsData($cache);

    // Redirect to the delete page.
    if (count($rows) == 0) {
      $route_parameters = [
        'paragraphs_type' => $this->paragraphsType->id(),
        'delete' => TRUE
      ];

      /** @var \Symfony\Component\HttpFoundation\RedirectResponse $response */
      $response = $this->redirect('entity.paragraphs_type.delete_form', $route_parameters);
      $response->send();
    }

    $header = [
      $this->t('Name')
    ];

    // Build form.
    $form['title'] = [
      '#markup' => $this->t('They are @count items whose paragraph content is to be deleted.', ['@count' => count($rows)]),
    ];

    $form['entities'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('They are no items to be viewed.')
    ];

    $form['#cache'] = [
      'tags' => $cache->getCacheTags(),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Delete'),
      '#button_type' => 'primary',
      '#name' => 'delete',
    );

    $form['actions']['cancel'] = array(
      '#value' => $this->t('Cancel'),
      '#type' => 'submit',
      '#name' => 'cancel',
    );

    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    /** @var \Drupal\paragraphs\Entity\ParagraphsType $paragraph */
    $paragraph = $this->paragraphsType;
    $triggering_element = $form_state->getTriggeringElement();

    switch ($triggering_element['#name']){
      case 'cancel':
        $this->redirect('entity.paragraphs_type.collection')->send();
        break;

      case 'delete':

        $this->getParagraphsData(FALSE, TRUE);

        // Flush all persistent caches.
        $this->moduleHandler->invokeAll('cache_flush');
        foreach (Cache::getBins() as $cache_backend) {
          $cache_backend->deleteAll();
        }

        $route_parameters = [
          'paragraphs_type' => $paragraph->id(),
          'delete' => TRUE
        ];

        /** @var \Symfony\Component\HttpFoundation\RedirectResponse $response */
        $response = $this->redirect('entity.paragraphs_type.delete_form', $route_parameters);
        $response->send();
        break;
    }

  }

  protected function getParagraphsData($cache, $delete = FALSE) {

    $paragraphs_table = self::PARAGRAPHS_TABLE;

    /** @var \Drupal\Core\Database\Driver\mysql\Select $query */
    $query = $this->connection->select("{$paragraphs_table}_field_data", 'pifd')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender');
    $query->fields('pifd', [
      'id',
      'type',
      'parent_type',
      'parent_field_name',
      'parent_id'
    ]);

    $query->condition('pifd.type', $this->paragraphsType->id());

    /** @var \Drupal\Core\Database\Driver\mysql\Select $count_query */
    $count_query = $this->connection->select("{$paragraphs_table}_field_data", 'pifd');
    $count_query->condition('pifd.type', $this->paragraphsType->id());
    $count_query->addExpression('COUNT(*)');

    $query->setCountQuery($count_query);

    /** @var \Drupal\Core\Database\Statement $results */
    $results = $query->execute();

    $rows = [];

    foreach ($results as $result) {
      switch ($result->parent_type){
        case 'node':

          if ($delete) {
            $this->deleteParagraphEntityData($result);
          }
          else {
            $node = Node::load($result->parent_id);
            $cache->addCacheableDependency($node);

            /** @var \Drupal\Core\Link $link */
            $link = $node->toLink($node->label());
            $rows[$node->id()] = [$link->toString()];
          }

          break;
      }
    }


    // Delete all the paragraph data.
    if ($delete) {
      $this->finalizeParagraphDataDeletion();
    }

    return $rows;
  }

  /**
   * Delete paragraph data from entities.
   *
   * @param object $result
   *   The database result object.
   */
  protected function deleteParagraphEntityData($result) {
    $paragraphs_table = self::PARAGRAPHS_TABLE;

    /** @var \Drupal\Core\Database\Driver\mysql\Schema $schema */
    $schema = $this->connection->schema();

    $table = "{$result->parent_type}__{$result->parent_field_name}";
    $revisions_table = "{$result->parent_type}_revision__{$result->parent_field_name}";

    if ($schema->tableExists($revisions_table)) {
      $this->connection->truncate($revisions_table)->execute();
    }

    if ($schema->tableExists($table)) {
      $this->connection->truncate($table)->execute();
    }

    // Delete all revisions of the paragraph to be deleted.
    $this->connection->delete("{$paragraphs_table}_revision")
      ->condition('id', $result->id)
      ->execute();
    $this->connection->delete("{$paragraphs_table}_revision_field_data")
      ->condition('id', $result->id)
      ->execute();
  }

  /**
   * Delete all paragraph data.
   */
  protected function finalizeParagraphDataDeletion() {
    $paragraphs_table = self::PARAGRAPHS_TABLE;
    $this->connection->delete("{$paragraphs_table}_field_data")
      ->condition('type', $this->paragraphsType->id())
      ->execute();
    $this->connection->delete($paragraphs_table)
      ->condition('type', $this->paragraphsType->id())
      ->execute();
  }

}
