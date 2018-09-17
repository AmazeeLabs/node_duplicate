# Node Duplicate

Exposes an action for duplicating nodes.

## Settings

### Clone Referenced

To clone entities of certain types/bundles referenced from the host entity:
```
drush -y cset node_duplicate.config clone_referenced.node.teaser 1
```

This setting is only applied to the "root" entity - the one for which user ran the Duplicate action. For all child entities this setting is ignored.
