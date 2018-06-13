<?php

namespace Drupal\node_duplicate\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList;
use Drupal\filter\Render\FilteredMarkup;

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
   * @param \Drupal\node\NodeInterface $entity
   *   Node to duplicate.
   *
   * @return \Drupal\node\NodeInterface
   *   Duplicated node.
   */
  public function execute($entity = NULL) {
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

          // Clone paragraphs.
          $this->cloneParagraphs($duplicated_entity);
        }
      }
    }

    $duplicated_entity->setPublished(FALSE);
    $duplicated_entity->setChangedTime(time());
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
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\node\NodeInterface $object */
    return $object->access('update', $account, $return_as_object);
  }

}
