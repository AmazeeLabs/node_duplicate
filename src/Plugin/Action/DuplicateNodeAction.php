<?php

namespace Drupal\node_duplicate\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TranslatableInterface;
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
   * Duplicates a node.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   Node to duplicate.
   *
   * @return \Drupal\node\NodeInterface
   *   Duplicated node.
   */
  public function execute($entity = NULL) {
    $duplicated_entity = $entity->createDuplicate();

    // Add the "Clone of " prefix to the entity label.
    if ($duplicated_entity instanceof TranslatableInterface) {
      if ($label_key = $duplicated_entity->getEntityType()->getKey('label')) {
        foreach ($duplicated_entity->getTranslationLanguages() as $language) {
          $langcode = $language->getId();
          $duplicated_entity = $duplicated_entity->getTranslation($langcode);

          $new_label = $this->t('Clone of @label', [
            '@label' => FilteredMarkup::create($duplicated_entity->label()),
          ], [
            'langcode' => $langcode,
          ]);
          $duplicated_entity->set($label_key, $new_label);

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

    $this->cloneEntities($duplicated_entity);

    $duplicated_entity->setPublished(FALSE);
    $duplicated_entity->setChangedTime(time());
    $duplicated_entity->save();
    return $duplicated_entity;
  }

  /**
   * Clone all entities.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   Node to duplicate.
   */
  protected function cloneEntities($entity) {
    foreach ($entity as $field) {
      if ($field instanceof \Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList) {
        foreach ($field as $field_item) {
          $field_item->entity = $field_item->entity->createDuplicate();
          $this->cloneEntities($field_item->entity);
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
