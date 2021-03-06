<?php
/**
* purgeSystemCommand class file
*
* This is the command for informing users of impending file deletions and deleting user files after they've been on the server for 30 days.
* @category Purge System
* @package Purge System
* @author State Library of North Carolina - Digital Information Management Program <digital.info@ncdcr.gov>
* @author Dean Farrell
* @version 1.4
* @license Unlicense {@link http://unlicense.org/}
* @todo update file_info table.  DON'T DELETE record.
*/

/**
* This is the command for informing users of impending file deletions and deleting user files after they've been on the server for 30 days.
* @author State Library of North Carolina - Digital Information Management Program <digital.info@ncdcr.gov>
* @author Dean Farrell
* @version 1.4
* @license Unlicense {@link http://unlicense.org/}
* @todo update file_info table.  DON'T DELETE record.
*/
class purgeSystemCommand extends CConsoleCommand {
	/**
	* gets file_info table
	* @var $file_info
	*/
	public $file_info = 'file_info';
	/**
	* Gets the the error file path (if there is one) for that day's processing.
	* @var $error_list
	*/
	public $error_list;
	/**
	* Implements the MailUser class
	* @var $mail_user
	*/
	public $mail_user;
	
	/**
	* Gets the the error file path (if there is one) for that day's processing.
	* Instantiates the MailUser class for use in notifying users of system purges
	*/
	public function __construct() {
		$this->error_list = Yii::getPathOfAlias('application.messages') . '/' . 'error_list_' . date('Y-m-d') . '.txt';
		$this->mail_user = new MailUser;
	}
	
	/**
	* Gets files that are more than 30 days old for deletion
	* Trying to keep it DB agnostic, would use DATE_SUB with MySQL
	* @access public
	* @return object Yii DAO object
	*/
	public function filesToDelete() { 
		$sql = "SELECT id, temp_file_path, file_type_id FROM file_info
			WHERE download_time <= :download_time
			AND   virus_check = :virus_check
			AND   checksum_run = :checksum_run
			AND   metadata = :metadata
			AND   temp_file_path != :path";
		
		$files = Yii::app()->db->createCommand($sql)
			->bindParam(':download_time', $this->timeOffset(30))
			->bindValue(':virus_check', 1)
			->bindValue(':checksum_run', 1)
			->bindValue(':metadata', 1) 
			->bindValue(':path', '')
			->limit(7500)
			->queryAll();
		
		return $files;
	}
	
	/**
	* Get generated csv and zip files to delete
	* creationdate is used with csv and zip files
	* Get user upload lists
	* Used process_time
	* @param $table
	* @access public
	* @return object Yii DAO object
	*/
	public function generatedFiles($table) {
		$field = ($table == 'upload') ? 'process_time' : 'creationdate';
		
		$sql = "SELECT id, path, user_id FROM $table WHERE $field <= :timeoffset";
		$generated_files = Yii::app()->db->createCommand($sql)
			->bindParam(':timeoffset', $this->timeOffset(30))
			->queryAll();
		
		return $generated_files;
	}
	
	/**
	* Get list of users with zip files that are at least 20 days old to send them notices of impending file deletions.
	* @access public
	* @return object Yii DAO object
	*/
	public function getUserReminders() {
		$sql = "SELECT id, user_id FROM zip_gz_downloads
			WHERE deletion_reminder = :deletion_reminder 
			AND creationdate <= :creationdate 
			GROUP BY user_id";
		
		$user_list = Yii::app()->db->createCommand($sql)
			->bindValue(':deletion_reminder', 0)
			->bindParam(':creationdate', $this->timeOffset(20))
			->queryAll();
		
		return $user_list;
	}
	
	/**
	* Update zip file list to show that file has been accounted for in email deletion reminders 
	* and user doens't need to be reminded again about this file.
	* @param $file_id
	* @access protected
	* @return object Yii DAO object
	*/
	protected function reminderSent($file_id) {
		$sql = "UPDATE zip_gz_downloads SET deletion_reminder = 1 WHERE id = ?";
		Yii::app()->db->createCommand($sql)
			->execute(array($file_id));
	}
	
	/**
	* Delete processed download lists, url lists, and ftp lists from the database.
	* These records aren't linked to a file on the server
	* 1 = processed
	* @param $table
	* @access protected
	* @return object Yii DAO object
	*/
	protected function clearLists($table) {
		$sql = "DELETE FROM $table WHERE processed = ?";
		Yii::app()->db->createCommand($sql)
			->execute(array(1));
	}
	
	/**
	* Get current date/time in ISO 8601 date format
	* @access protected
	* @return string
	*/
	protected function getDateTime() {
		return date('c');
	}
	
	/**
	* Doing it this way so it'll work in SQLite and MySQL.  SQLite doesn't have DATE_SUB() function
	* Returns something like 2012-03-04 14:23:46
	* @param $offset
	* @access protected
	* @return string
	*/
	protected function timeOffset($offset = 30) {
		$time = time() - ($offset * 24 * 60 * 60);
		
		return date('Y-m-d H:i:s', $time);
	}
	
	/**
	* Update file_info table if an expired file is successfully deleted
	* @param $file_id
	* @access private
	* @return object Yii DAO object
	*/
	private function updateFileInfo($file_id) {
		$sql = "UPDATE file_info SET temp_file_path = NULL, expired_deleted = 1 WHERE id = ?";
		Yii::app()->db->createCommand($sql)
			->execute(array($file_id));
	}
	
	/**
	* Update a generated file table: zip, csv, metadata
	* @param $table
	* @param $file_id
	* @access private
	* @return object Yii DAO object
	*/
	private function updateGenerated($table, $file_id) {
		$sql = "DELETE FROM $table WHERE id = ?";
		Yii::app()->db->createCommand($sql)
			->execute(array($file_id));
	}
	
	/**
	* Remove file from the file system
	* @param $file_path
	* @param $file_id
	* @param $table
	* @access public
	* @return boolean
	*/
	public function removeFile($file_path, $file_id, $table = 'file_info') {
		$delete_file = @unlink($file_path);
			
		if($delete_file == false) {
			$this->logError($this->getDateTime() . " - $file_id, with path: $file_path could not be deleted.");
		} elseif($table == 'file_info') {
			$this->updateFileInfo($file_id);
		} else {
			$this->updateGenerated($table, $file_id);
		}
		
		if($delete_file) { echo $file_path . " deleted\r\n"; }
	}
	
	/**
	* http://stackoverflow.com/questions/4747905/how-can-you-find-all-immediate-sub-directories-of-the-current-dir-on-linux
	* Deletes empty directories in a given path
	* @param $dir_path
	* @access public
	*/
	public function removeDir($dir_path) {
		exec(escapeshellcmd('find ' . $dir_path . ' -type d'), $dirs);
		unset($dirs[0]); // this is the base dir for the downloads/uploads so leave it there
		
		foreach($dirs as $dir) {
			$count = count(scandir($dir));
			
			if($count == 2) { // check for . and .. directories
				$delete_dir = @rmdir($dir);
				
				if($delete_dir == false) {
					$this->logError($this->getDateTime() . " - Directory: $dir could not be deleted.");
				} else {
					echo $dir . " deleted\r\n";
				}
			} else {
				echo $dir . " is not empty\r\n";
			}

		}
	} 
	
	/**
	* Loop through the list of files to delete, remove them
	* @param $files
	* @param $table
	* @access public
	*/
	public function fileProcess($files, $table) {
		if(is_array($files) && !empty($files)) {
			foreach($files as $file) {
				$this->removeFile($file['path'], $file['id'], $table);
			}
		}
	}
	
	/**
	* Writes file and directory deletion failures to file.
	* @param $error_text
	* @access protected
	*/
	protected function logError($error_text) {
		$fh = fopen($this->error_list, 'ab');
		fwrite($fh, $error_text . ",\r\n");
		fclose($fh);
	}
	
	/**
	* Mails file and directory deletion failures to sys. admin
	* @access protected
	*/
	protected function mailError() {
		if(file_exists($this->error_list)) {
			$to_from = Yii::app()->params['adminEmail'];
			$subject = 'Cinch file and directory deletion errors';
			
			$message = "The following deletion errors occured:\r\n";
			$message .= file_get_contents($this->error_list);
			
			mail($to_from, $subject, $message, "From: " . $to_from . "\r\n");
		} else {
			return false;
		}
	}
	
	/**
	* Clears generated file from zip_gz_downloads, csv_meta_paths, and upload tables from the filesystem.
	* Updates appropriate tables accordingly.
    * @access public
	*/
	public function clearGenerated() {
		$tables =  array('zip_gz_downloads', 'csv_meta_paths', 'upload');
		
		foreach($tables as $table) {
			$files = $this->generatedFiles($table);
			$this->fileProcess($files, $table);
		}
	}
	
	/**
	* Clears processed downloads from files_for_download and upload tables.
	* Bit of a hack for upload table.  Left in as a bug wasn't deleting upload db entries properly.
	* This way the database will be cleaned up appropriately.
	*/
	public function clearProcessed() {
		$tables = array('files_for_download'); // upload
		foreach($tables as $table) {
			$this->clearLists($table);
		}
	}
	
	/**
	* Mails user a reminder that they have files what will be deleted in 10 days.
	* @access public
	*/
	public function actionCheck() {
		$users = $this->getUserReminders();
		if(empty($users)) { echo "No users need reminding."; exit; }
		
		$subject = 'You have files on CINCH! marked for deletion';
			
		$message = "You have files marked for deletion from Cinch!\r\n";
		$message .= "They will be deleted 10 days from now.\r\n";
		$message .= "If you haven't done so please retrieve your downloads soon from http://cinch.nclive.org.\r\n";
		$message .= "\r\n";
		$message .= "Thanks, from your CINCH administrators";
		
		foreach($users as $user) {
			$mail_sent = $this->mail_user->UserMail($user['user_id'], $subject, $message);
			if($mail_sent) {
				$this->reminderSent($user['id']);
			}
		}
	}
	
	/**
	* Implements class methods to delete expired files and directories from the system.
    * @access public
	*/
	public function actionDelete() {
		$this->clearGenerated();
		$this->clearProcessed(); 
		
		$downloaded_files = $this->filesToDelete();
		if(is_array($downloaded_files) && !empty($downloaded_files)) {
			foreach($downloaded_files as $downloaded_file) {
				$this->removeFile($downloaded_file['temp_file_path'], $downloaded_file['id']);
			}
		} 
		
		$user_dirs = array('uploads', 'curl_downloads'); // remove empty directories
		foreach($user_dirs as $user_dir) {
			$this->removeDir(Yii::getPathOfAlias('application.' . $user_dir));
		}
		
		$this->mailError(); 
	}
}