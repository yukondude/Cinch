<?php
abstract class FileTypeActiveRecord extends CActiveRecord {

	public function writeMetadata() {}
	
	/**
	* Returned metadata fields vary by document, not just doc type.
	* This finds the interection of returned metadata with file type table fields.
	* @access public
	* @return array
	*/
	public function returnedFields(array $possible_query_fields, array $metadata) {
		return array_intersect_key($possible_query_fields, $metadata);
	}
	
	/**
	* Flattens table fields into a string for query building.
	* Adds : if creating prepared statement bindings.
	* @access public
	* @return string
	*/
	public function queryBuilder(array $fields, $prepare = false) {
		if($prepare != false) {
			foreach($fields as $key => $field) {
				$fields[$key] = '?';
			}
		}
		return implode(',', $fields);
	}
	
	/**
	* Generates bind parameters on queries and cleans field values for insertion.
	* Each tika value starts with : so need to remove it.
	* @access public
	* @return array
	*/
	public function bindValuesBuilder(array $fields, array $metadata) {
		$params = array();

		foreach($metadata as $key => $value) {
			if(array_key_exists($key, $fields)) {
				$param_value = preg_replace('/^:\s/', '', strip_tags(trim($value)));
				$params[] = $param_value;
			} else {
				continue;
			}
		}
		
		return $params;
	}
	
	/**
	* Merges file and/or user id info onto the end of the metadata array
	* @access public
	* @return array
	*/
	public function addIdInfo(array $metadata_fields, $id_values) {
		if(is_array($id_values)) {
			return $field_list = array_merge($metadata_fields, $id_values);
		} else {
			return $metadata_fields[$id_values] = $id_values;
		}
	}
}