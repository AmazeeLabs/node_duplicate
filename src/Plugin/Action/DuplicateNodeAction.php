<?php

namespace Drupal\node_duplicate\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList;
use Drupal\filter\Render\FilteredMarkup;
use Drupal\node\NodeInterface;

/**
 * Duplicates a node.
 *
 * @Action(
 *   id = "node_duplicate_action",
 *   label = @Translation("Duplicate node"),
 *   type = "node"
 * )
 */
class DuplicateNodeAction extends ActionBase {

  /**
   * Clones of referenced entities.
   *
   * Reason: each referenced entity (that has to be cloned) needs to have
   * exactly one clone.
   *
   * Structure:
   * [
   *   {entity-type-id} => [
   *     {original-entity-id} => {entity-clone},
   *     ...
   *   ],
   *   ...
   * ]
   *
   * @var array
   */
  protected $clonedChildEntities = [];

  /**
   * @inheritdoc
   *
   * The admin content view displays a row for each node translation. When
   * several translations of the same node is selected, they all will be passed
   * to the action. This can be quite confusing for users. Mostly, they expect
   * to get all translations cloned.
   *
   * So we don't pass all translations to execute(), we pass only one per
   * entity. And execute() iterates over all existing entity translations.
   */
  public function executeMultiple(array $entities) {
    $processed_ids = [];
    foreach ($entities as $entity) {
      if (!in_array($entity->id(), $processed_ids, TRUE)) {
        $this->execute($entity);
        $processed_ids[] = $entity->id();
      }
    }
  }

  /**
   * Duplicates a node and all its translations.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Node to duplicate.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Duplicated node.
   */
  public function execute($entity = NULL) {
    return $this->cloneEntity($entity, TRUE);
  }


  /**
   * Duplicates a node and all its translations.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Node to duplicate.
   * @param bool $root_call
   *   Indicates that the method was called from the execute method.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Duplicated node.
   */
  protected function cloneEntity($entity, $root_call) {
    if ($root_call) {
      $this->clonedChildEntities = [];
    }

    $duplicated_entity = $entity->createDuplicate();

    if ($duplicated_entity instanceof TranslatableInterface) {
      if ($label_key = $duplicated_entity->getEntityType()->getKey('label')) {
        foreach ($duplicated_entity->getTranslationLanguages() as $language) {
          $langcode = $language->getId();
          $duplicated_entity = $duplicated_entity->getTranslation($langcode);

          // Add the "Clone of " prefix to the entity label.
          $new_label = $this->t('Clone of @label', [
            '@label' => FilteredMarkup::create($duplicated_entity->label()),
          ], [
            'langcode' => $langcode,
          ]);
          $duplicated_entity->set($label_key, $new_label);

          // And to the admin title if it exists.
          $admin_title_key = 'field_admin_title';
          if ($duplicated_entity->hasField($admin_title_key)) {
            $current_title = $duplicated_entity->get($admin_title_key)->value;
            if (!empty($current_title)) {
              $new_admin_title = $this->t('Clone of @label', [
                '@label' => FilteredMarkup::create($current_title),
              ], [
                'langcode' => $langcode,
              ]);
              $duplicated_entity->set($admin_title_key, $new_admin_title);
            }
          }
        }
      }
    }

    // Clone paragraphs and referenced entities (if required).
    foreach ($duplicated_entity->getTranslationLanguages() as $language) {
      $langcode = $language->getId();
      $duplicated_entity = $duplicated_entity->getTranslation($langcode);
      $this->cloneParagraphs($duplicated_entity);
      if ($root_call) {
        $this->cloneReferencedEntities($duplicated_entity);
      }
    }

    if ($duplicated_entity instanceof NodeInterface) {
      $duplicated_entity->setPublished(FALSE);
      $duplicated_entity->setChangedTime(time());
    }

    if ($entity->hasField('path')) {
      $duplicated_entity->path = [
        'alias' => null,
        'pathauto' => false,
      ];
      $duplicated_entity->save();
      \Drupal\Core\Cache\Cache::invalidateTags($duplicated_entity->getCacheTags());
      pathauto_entity_insert($duplicated_entity);
    }

    return $duplicated_entity;
  }

  /**
   * Recursively clones all paragraphs.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   */
  protected function cloneParagraphs($entity) {
    foreach ($entity as $field) {
      if ($field instanceof EntityReferenceRevisionsFieldItemList) {
        foreach ($field as $field_item) {
          $field_item->entity = $field_item->entity->createDuplicate();
          $this->cloneParagraphs($field_item->entity);
        }
      }
    }
  }

  /**
   * Clones referenced entities if it's required by the config.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function cloneReferencedEntities($entity) {
    $enabled = \Drupal::config('node_duplicate.config')->get('clone_referenced');
    if (empty($enabled)) {
      return;
    }

    foreach ($entity as $field) {
      if ($field instanceof EntityReferenceRevisionsFieldItemList) {
        foreach ($field as $field_item) {
          $this->cloneReferencedEntities($field_item->entity);
        }
      }
      elseif ($field instanceof EntityReferenceFieldItemList) {
        foreach ($field as $field_item) {
          $child_entity = $field_item->entity;
          if ($child_entity instanceof ContentEntityInterface) {
            $type = $child_entity->getEntityTypeId();
            $bundle = $child_entity->bundle();
            if (!empty($enabled[$type][$bundle])) {
              $id = $child_entity->id();
              if (!isset($this->clonedChildEntities[$type][$id])) {
                $clone = $this->cloneEntity($child_entity, FALSE);
                $this->clonedChildEntities[$type][$id] = $clone;
                // It can be possible that entity translations refer to the same
                // child entity. In this case, when we process the first
                // translation, we override the child entity with a clone. Then,
                // when we process the second translation, it already refers to
                // the clone, so we can accidentally clone a clone.
                // To avoid this, also save the clone ID in clonedChildEntities.
                $this->clonedChildEntities[$type][$clone->id()] = $clone;
              }
              $field_item->entity = $this->clonedChildEntities[$type][$id];
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\node\NodeInterface $object */
    return $object->access('update', $account, $return_as_object);
  }

}
