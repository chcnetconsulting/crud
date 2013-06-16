<?php

App::uses('CrudListener', 'Crud.Controller/Crud');

/**
 * Field Filter Listener
 *
 * Allow the requester to decide what fields and relations that should be
 * returned by providing a `fields` GET argument with a comma separated list of fields.
 *
 * For a relation automatically to be joined, it has to be whitelisted first.
 * If no whitelist exist, no relations will be added automatically
 * `$this->_crud->action()->config('fieldFilter.models', array('list', 'of', 'models'))`
 *
 * You can also whitelist fields, if no whitelist exist for fields, all fields are allowed
 * If whitelisting exist, only those fields will be allowed to be selected.
 * The fields must be in `Model.field` format
 * `$this->_crud->action()->config('fieldFilter.fields.whitelist', array('Model.id', 'Model.name', 'Model.created'))`
 *
 * You can also blacklist fields, if no blacklist exist, no blacklisting is done
 * If blacklisting exist, the field will be removed from the field list if present
 * The fields must be in `Model.field` format
 * `$this->_crud->action()->config('fieldFilter.fields.blacklist', array('Model.password', 'Model.auth_token', 'Model.created'))`
 *
 * This is probably only useful if it's used in conjunction with the ApiListener
 *
 * Limitation: Related models is only supported in 1 level away from the primary model at
 * this time. E.g. "Blog" => Auth, Tag, Posts
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Christian Winther, 2013
 */
class FieldFilterListener extends CrudListener {

/**
 * Returns a list of all events that will fire in the controller during it's lifecycle.
 * You can override this function to add you own listener callbacks
 *
 * We attach at priority 50 so normal bound events can run before us
 *
 * @return array
 */
	public function implementedEvents() {
		return array(
			'Crud.beforePaginate' => array('callable' => 'beforePaginate', 'priority' => 50),
			'Crud.beforeFind' => array('callable' => 'beforeFind', 'priority' => 50)
		);
	}

/**
 * List of relations that should be contained
 *
 * @var array
 */
	protected $_relations = array();

/**
 * beforeFind
 *
 * @param CakeEvent $event
 * @return void
 */
	public function beforeFind(CakeEvent $event) {
		$fields = $this->_getFields($event);
		if (empty($fields)) {
			return;
		}

		$event->subject->query['fields'] = array_unique($fields);
		$event->subject->query['contain'] = $this->_relations;
	}

/**
 * beforePaginate
 *
 * @codeCoverageIgnore This is exactly the same as beforeFind()
 * @param CakeEvent $event
 * @return void
 */
	public function beforePaginate(CakeEvent $event) {
		$fields = $this->_getFields($event);
		if (empty($fields)) {
			return;
		}

		$this->_controller->paginate['fields'] = $fields;
		$this->_controller->paginate['contain'] = $this->_relations;
	}

/**
 * Whitelist fields that are allowed to be included in the
 * output list of fields
 *
 * @param array $fields
 * @param string $action
 * @return mixed
 */
	public function whitelistFields($fields = null, $action = null) {
		if (empty($fields)) {
			return $this->_crud->action($action)->config('fieldFilter.fields.whitelist');
		}

		$this->_crud->action($action)->config('fieldFilter.fields.whitelist', $fields);
	}

/**
 * Blacklist fields that are not allowed to be included in the
 * output list of fields
 *
 * @param array $fields
 * @param string $action
 * @return mixed
 */
	public function blacklistFields($fields = null, $action = null) {
		if (empty($fields)) {
			return $this->_crud->action($action)->config('fieldFilter.fields.blacklist');
		}

		$this->_crud->action($action)->config('fieldFilter.fields.blacklist', $fields);
	}

/**
 * Whitelist associated models that are allowed to be included in the
 * output list of fields
 *
 * @param array $models
 * @param string $action
 * @return mixed
 */
	public function whitelistModels($models = null, $action = null) {
		if (empty($models)) {
			return $this->_crud->action($action)->config('fieldFilter.models.whitelist');
		}

		$this->_crud->action($action)->config('fieldFilter.models.whitelist', $models);
	}

/**
 * Can the client make a request without specifying the fields he wants
 * returned?
 *
 * This will bypass all black- and white- listing if set to true
 *
 * @param boolean $permit
 * @param string $action
 * @return boolean
 */
	public function allowNoFilter($permit = null, $action = null) {
		if (empty($permit)) {
			return (bool)$this->_crud->action($action)->config('fieldFilter.allowNoFilter');
		}

		$this->_crud->action($action)->config('fieldFilter.allowNoFilter', (bool)$permit);
	}

/**
 * Get fields for the query
 *
 * @param CakeEvent $event
 * @return array
 * @throws CakeException If fields not specified
 */
	protected function _getFields(CakeEvent $event) {
		$this->_relations = array();
		$fields = $this->_getFieldsForQuery($event->subject->model);
		if (empty($fields) && !$this->allowNoFilter(null, $event->subject->action)) {
			throw new CakeException('Please specify which fields you would like to select');
		}
		return $fields;
	}

/**
 * Get the list of fields that should be selected
 * in the query based on the HTTP GET requests fields
 *
 * @param Model $model
 * @return array
 */
	protected function _getFieldsForQuery(Model $model) {
		$fields = $this->_getFieldsFromRequest();
		if (empty($fields)) {
			return;
		}

		$newFields = array();
		foreach ($fields as $field) {
			$fieldName = $this->_checkField($model, $field);

			// The field should not be included in the query
			if (empty($fieldName)) {
				continue;
			}

			$newFields[] = $fieldName;
		}

		return $newFields;
	}

/**
 * Get a list of fields from the HTTP request
 *
 * It's assumed the fields are comma separated
 *
 * @return array
 */
	protected function _getFieldsFromRequest() {
		$query = $this->_request->query;
		if (empty($query['fields'])) {
			return;
		}

		return array_unique(array_filter(explode(',', $query['fields'])));
	}

/**
 * Secure a field - check that the field exist in the model
 * or a closely related model
 *
 * If the field doesn't exist, it's removed from the
 * field list.
 *
 * @param Model $model
 * @param string $field
 * @return mixed
 */
	protected function _checkField(Model $model, $field) {
		list ($modelName, $fieldName) = pluginSplit($field, false);

		// Prefix fields that don't have a model key with the local model name
		if (empty($modelName)) {
			$modelName = $model->alias;
		}

		// If the model name is the local one, check if the field exist
		if ($modelName === $model->alias && !$model->hasField($fieldName)) {
			return false;
		}

		// Check associated models if the field exist there
		if ($modelName !== $model->alias) {
			if (!$this->_associatedModelHasField($model, $modelName, $fieldName)) {
				return false;
			}
		}

		$fullFieldName = sprintf('%s.%s', $modelName, $fieldName);
		if (!$this->_whitelistedField($fullFieldName)) {
			return;
		}

		if ($this->_blacklistedField($fullFieldName)) {
			return;
		}

		if ($modelName != $model->alias) {
			$this->_relations[] = $modelName;
		}

		return $fullFieldName;
	}

/**
 * Check if the associated `modelName` to the `$model`
 * exist and if it have the field in question
 *
 * @param Model $model
 * @param string $modelName
 * @param string $fieldName
 * @return boolean
 */
	protected function _associatedModelHasField(Model $model, $modelName, $fieldName) {
		$associated = $model->getAssociated();
		if (!array_key_exists($modelName, $associated)) {
			return false;
		}

		if (!$this->_whitelistedAssociatedModel($modelName)) {
			return false;
		}

		return $model->{$modelName}->hasField($fieldName);
	}

/**
 * Check if the associated model is whitelisted to be automatically
 * contained on demand or not
 *
 * If no whitelisting exists, no associated models may be joined
 *
 * @param string $modelName
 * @return boolean
 */
	protected function _whitelistedAssociatedModel($modelName) {
		$allowedModels = $this->whitelistModels();
		if (empty($allowedModels)) {
			return false;
		}

		return false !== array_search($modelName, $allowedModels);
	}

/**
 * Check if a field has been whitelisted
 *
 * If no field whitelisting has been done, all fields
 * are allowed to be selected
 *
 * @param string $fieldName
 * @return boolean
 */
	protected function _whitelistedField($fieldName) {
		$allowedFields = $this->whitelistFields();
		if (empty($allowedFields)) {
			return true;
		}

		return false !== in_array($fieldName, $allowedFields);
	}

/**
 * Check if a field has been blacklisted
 *
 * @param string $fieldName
 * @return boolean
 */
	protected function _blacklistedField($fieldName) {
		$disallowedFields = $this->blacklistFields();
		if (empty($disallowedFields)) {
			return false;
		}

		return false !== in_array($fieldName, $disallowedFields);
	}

}
