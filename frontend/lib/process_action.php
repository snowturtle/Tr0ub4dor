<?php
/* $Id$ */
  session_start();
  // TODO: Clean up error handling code. Including better messages 
  // if something goes wrong.
  require_once("config.php");
  require_once("common_func.php");  
  check_referrer(BASE_DOMAIN);

  // Session is active
  test_session();
  
  // Default values
  $msg__ = '';
  
  //------------------------------------------
  // BEGIN: Supporting Functions
  //------------------------------------------
	function change_password($new_crypt_pw, $old_key, $new_key, $ver) {
  //------------------------------------------
  // Performs the actual changing of the 
  // master password.
  //------------------------------------------
    $success = 1;
    // Set db object
    $db = get_db_conn();
    
    // Retrieve all the wallet entries, which we will then decode and reencode, using the new password.
		$list = $db->out_result_object("select * from wallet;");

    // Loop through the entries
		while ( $thisline = $list->fetch_object() ) {

      $sql__ = "INSERT INTO wallet VALUES('','" .
				$db->conn->real_escape_string(en_crypt(de_crypt($thisline->itemname, $old_key), $new_key)) . "','" .
				$db->conn->real_escape_string(en_crypt(de_crypt($thisline->host, $old_key), $new_key)) . "','" .
				$db->conn->real_escape_string(en_crypt(de_crypt($thisline->login, $old_key), $new_key)) . "','" .
				$db->conn->real_escape_string(en_crypt(de_crypt($thisline->pw, $old_key), $new_key)) . "','" .
				$db->conn->real_escape_string(en_crypt(de_crypt($thisline->comment, $old_key), $new_key)) . "')";
			
      // Insert the new entry, reencoded.
      $insert_result = $db->in_sql_no_data($sql__);
      // Delete the original entry.
			$delete_result = $db->in_sql_no_data("DELETE FROM wallet WHERE ID=" . $thisline->ID);

		} // while loop

    // Delete the original password
		$db->in_sql_no_data("DELETE FROM main");
    // Insert the new password
		$result = $db->in_sql_no_data("INSERT INTO main VALUES ('" . $ver . "','" . $new_crypt_pw . "')");
    
		if ($result == 0) {
			$success = 0;
			// try to delete so that the table remains clean
			//$db->in_sql_no_data("DELETE FROM main");
		}

    unset($db);
		return $success;
	} //change_password()
  
  //------------------------------------------
  // END: Supporting Functions
  //------------------------------------------
  

  //------------------------------------------
  // BEGIN: Main logic block
  //------------------------------------------
  // Require POST
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { go_to_url('../' . PAGE_LOGIN); }
  
  // Any actions to perform?
  if (isset($_POST['action'])) {

    // Since we have an action to perform, initiate the db object
    $db = get_db_conn();
    
    switch (strtolower($_POST['action'])) {
      //---------------------------------------
      // Save new entry
      //---------------------------------------
      case "save":

        $list = $db->in_sql_no_data("INSERT INTO wallet VALUES('','" .
          $db->conn->real_escape_string(en_crypt($_POST['itemname'], $_SESSION['key'])) . "','" .
          $db->conn->real_escape_string(en_crypt($_POST['host'], $_SESSION['key'])) . "','" .
          $db->conn->real_escape_string(en_crypt($_POST['login'], $_SESSION['key'])) . "','" .
          $db->conn->real_escape_string(en_crypt($_POST['password'], $_SESSION['key'])) . "','" .
          $db->conn->real_escape_string(en_crypt($_POST['comment'], $_SESSION['key'])) . "');");

          if ($list > 0) {
          $msg__ = 'Entry inserted successfully';
        } else {
          $msg__ = 'Entry not inserted';
        }          
        
        unset($db, $_POST['itemname'], $_POST['host'], $_POST['login'], $_POST['password'], $_POST['comment']);
        break; //save
    
    
      //---------------------------------------
      // Save edited entry
      //---------------------------------------
      case "editsave":

        $list = $db->in_sql_no_data("UPDATE wallet SET itemname='" . $db->conn->real_escape_string(en_crypt($_POST['itemname'], $_SESSION['key'])) .
          "', host='" . $db->conn->real_escape_string(en_crypt($_POST['host'], $_SESSION['key'])) .
          "', login='" . $db->conn->real_escape_string(en_crypt($_POST['login'], $_SESSION['key'])) .
          "', pw='" . $db->conn->real_escape_string(en_crypt($_POST['password'], $_SESSION['key'])) .
          "', comment='" . $db->conn->real_escape_string(en_crypt($_POST['comment'], $_SESSION['key'])) .
          "' WHERE ID=" . $_POST['ID']);

        unset($db, $_POST['itemname'], $_POST['host'], $_POST['login'], $_POST['password'], $_POST['comment']);

        if ($list > 0) {
          $msg__ = 'Entry updated successfully';
        } else {
          $msg__ = 'Entry not updated';
        }          
        break;  //editsave
      
    
      //---------------------------------------
      // Delete entry
      //---------------------------------------
      case "reallydelete":

        $num_rows = $db->in_sql_no_data("DELETE FROM wallet WHERE ID=".$_POST['ID']);

        unset($db);
        
        if ($num_rows > 0) {
          $msg__ = 'Entry deleted successfully';
        } else {
          $msg__ = 'Entry not deleted';
        }
        break;  //delete

    
      //---------------------------------------
      // Import uploaded file
      //---------------------------------------
      case "import":
  
        $row = $_POST['row'];
           
        // sort header_fields by occurence
        asort($row);
        
        // finally import the data
        $fd = fopen (TMP_PATH . TMP_IMPORT_FILE, "r");
        $insert_count = 0;
        
        while ($data = fgetcsv ($fd, 4096, ";")) {
          if (count($data) > 1) {
            $mysql_string = "INSERT INTO wallet VALUES(''";

            reset($_POST['row']);
            while (list ($index, $val) = each ($_POST['row'])) {
              $mysql_string .= ",'" . $db->conn->real_escape_string(en_crypt($data[$val],$_SESSION['key'])) . "'";
            }
            $mysql_string .= ")";
            $rows = $db->in_sql_no_data($mysql_string);
            
            if ($rows > 0) {
              $insert_count++;
            }
            unset($mysql_string);
          }
        }
        fclose ($fd);

        // TODO: Make this compare between the # of rows in the import file.
        if ($insert_count > 0) {
          $msg__ = 'Import finished successfully';
        } else {
          $msg__ = 'Import failed';
        }

        unset($row, $data, $db);
        break;  //import

    
      //---------------------------------------
      // Change Master Password
      //---------------------------------------
      case "changepw":

        // reconfirm existing password
        $cleartext_pw = "";
        
        // encrypt the pw given at logon
        if (isset($_POST['pw']) && strlen($_POST['pw']) > 0) {
          $cleartext_pw = $_POST['pw'];
          unset($_POST['pw']);
          
        } else {
          $sysmsg__ = '<br /><b>Fill in all fields before continuing</b>....Please <a href="chgpass.php">try again</a>.';
          show_sys_msg($sysmsg__);
          exit;

        }
        
        $crypt_pw = sha1($cleartext_pw);

        // check pw
        $entries = $db->out_row_object("SELECT version, pw FROM main");
        $db_pw = $entries->pw;
        $ver = $entries->version;

        if ($crypt_pw == $db_pw) {
          // password match - proceed

          // confirm new passwords match
          if ((isset($_POST['newpw']) && isset($_POST['confirm'])) && (strlen($_POST['newpw']) > 0 && strlen($_POST['confirm']) > 0)) {
            $newpw = $_POST['newpw'];
            $confirm = $_POST['confirm'];
            
            if ($newpw == $confirm) {
              // new passwords match, proceed
              $old_key = $_SESSION['key'];
              $new_key = md5("%dJ9&".strtolower($newpw)."(/&k.=".strtoupper($newpw)."1x&%");

              $new_crypt_pw = sha1($newpw);
              
              // do the actual change
              $res__ = change_password($new_crypt_pw, $old_key, $new_key, $ver);

              if ($res__ == 0) {
                $sysmsg__ = '<br /><b>Password change FAILED</b>....Please <a href="chgpass.php">try again</a>.';
              } else {
                session_unset();
                session_destroy();
                $sysmsg__ = '<br /><b>Password change SUCCESSFUL</b>....Your new password is <span class="sensitive">' . $newpw . '</span>. Please <a href="index.php">log in</a> with this new password.';
              }
              show_sys_msg($sysmsg__);
              exit;
              
            } else {
              //Not all filled out.
              $sysmsg__ = '<br /><b>New Password does not match Confirm New Password</b>....Please <a href="chgpass.php">try again</a>.';
              show_sys_msg($sysmsg__);
              exit;
              
            }
          } else {
            $sysmsg__ = '<br /><b>Fill in all fields before continuing</b>....Please <a href="chgpass.php">try again</a>.';
            show_sys_msg($sysmsg__);
            exit;

          }
          
        } else {
          $sysmsg__ = '<br /><b>Old Master Password does not match</b>....Please <a href="chgpass.php">try again</a>.';
          show_sys_msg($sysmsg__);
          exit;
          
        } // old pws match

        break;  // changepw
        
      case "export":

        $set = $db->out_result_object("select * from wallet;");
        
        $csv_out = "";
        while ($rec = $set->fetch_object()) {	
          $csv_out .= '"' . html_entity_decode(de_crypt($rec->itemname, $_SESSION['key'])) . '"' . CSV_DELIM . 
                      '"' . html_entity_decode(de_crypt($rec->host, $_SESSION['key'])) . '"' . CSV_DELIM . 
                      '"' . html_entity_decode(de_crypt($rec->login, $_SESSION['key'])) . '"' . CSV_DELIM . 
                      '"' . html_entity_decode(de_crypt($rec->pw, $_SESSION['key'])) . '"' . CSV_DELIM . 
                      '"' . html_entity_decode(de_crypt($rec->comment, $_SESSION['key'])) . '"' . "\n";
        }
      
        header('Content-type: application/octet-stream');
        header('Content-Disposition: attachment; filename="wallet_dump.csv"; size="' . strlen($csv_out) . '"');
        echo $csv_out;
        //$msg__ = 'Export completed successfully';
        exit;
        break;
    
    } // end switch
      
    // Set msg and then forward to main page
    if (strlen($msg__) > 0) {
      $_SESSION['msg'] = $msg__;
    }
    
  } // check $_POST['action']
  
  go_to_url('../' . PAGE_MAIN);  
  //------------------------------------------
  // END: Main logic block
  //------------------------------------------    
?>