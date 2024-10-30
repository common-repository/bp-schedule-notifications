<?php
/*
Plugin Name: BP Schedule Notifications
Plugin URI: http://www.aheadzen.com
Description: Buddypress Schedule Notifications as per you future prediction. 
Version: 1.0.5
Author: Ask Oracle Team
Author URI: http://ask-oracle.com/

Copyright: © 2014-2015 ASK-ORACLE.COM
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

class az_future_schedule {
	private static $instance;
	public $daysInAdvance;
	public $daysInMinus;
	
	public static function getInstance() {
		if( null == self::$instance ) {
			self::$instance = new az_future_schedule();
		} // end if
		return self::$instance;
	}
	
	private function __construct() {
		add_action( 'bp_init', array( $this, 'future_schedule_init' ) );
		register_activation_hook( __FILE__, array( $this, 'register_project_data' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deregister_project_data' ) );
		
		register_activation_hook( __FILE__, array( $this, 'future_notification_schedule' ) );
		register_deactivation_hook( __FILE__, array( $this, 'future_notification_schedule_deactivation') );		
		add_action( 'future_notification_event', array( $this, 'send_future_notification') );
		$this->daysInAdvance = strtotime('+7 days');
		$this->daysInMinus = strtotime('-7 days');
	}

	function future_schedule_init(){	
		add_action( 'az_birthchart_report_after_save', array( $this, 'manage_birthchart_future_schedule' ) );
		if($_GET['test']){
			$arg = array('user_id' =>$_GET['user_id'], 'pid' => $_GET['pid']);
			//self::manage_birthchart_future_schedule($arg);
			//global $wpdb; $wpdb->query("delete from ask_future_schedule");
			self::send_future_notification();			
		}
	}
	
	public function register_project_data() {
		global $wpdb, $table_prefix;
		$tbl_sql = "CREATE TABLE IF NOT EXISTS `".$table_prefix."future_schedule` (
		 `fsid` int(11) NOT NULL AUTO_INCREMENT,
		  `bchartid` int(11) NOT NULL,
		  `sdate` datetime NOT NULL,
		  `data` text NOT NULL,
		  `status` tinyint(2) NOT NULL DEFAULT '1',
		  PRIMARY KEY (`fsid`)
		)";
		$wpdb->query($tbl_sql);
		
	}
	
	public function deregister_project_data() {
		
	}
	
	public function manage_birthchart_future_schedule($args=array()) {
		global $wpdb, $table_prefix,$tbl_future_schedule;
		$tbl_future_schedule = $table_prefix.'future_schedule';		
		
		$user_id = $args['user_id'];
		$pid = $args['pid'];
		if(!$pid){$pid=0;}
		
		$profileuser = get_userdata($user_id);
		$birth_data = unserialize( $profileuser->birth_data );
		require_once CHARTS_INCLUDE_DIR . 'functions.php';
		if($birth_data['has_all_info']){
			//require_once CHARTS_INCLUDE_DIR . 'Atlas.php';
			//require_once CHARTS_INCLUDE_DIR . 'orbit.php';
			//require_once CHARTS_INCLUDE_DIR . 'planet.php';
			//require_once CHARTS_INCLUDE_DIR . 'transit.php';
			require_once CHARTS_INCLUDE_DIR . 'astroreport.php';
			require_once CHARTS_INCLUDE_DIR . 'AspectsGenerator.php';
			require_once CHARTS_INCLUDE_DIR . 'VimshottariDasha.php';
			
			global $wpdb;
			if($pid>0){
				$deleteSql = "delete from $tbl_future_schedule where bchartid=\"$pid\"";
				$wpdb->query($deleteSql);
			}
			
			$aa = new AstroReport( $birth_data );
			$planets = $aa->getPlanets();
			$birthTS = getBirthTS( $birth_data );
			//$birthTS = date('Y-m-d h:i:s');
			$dasha = new VimshottariDasha($planets['Moon']['fulldegree'],$birthTS);
			//$dashasince = $dasha->getDashaSince($birthTS);
			$dashasince = $dasha->getDashaSince(time(), 1, 3);
			
			self::getInsertSqlDasha($pid,$dashasince,array(),$birth_data);
			self::getInsertSqlTransit($pid,$birth_data,array());
		}
	}
	
	function getInsertSqlTransit($pid,$birth_data){
		global $wpdb, $table_prefix,$tbl_future_schedule;
		$a = new AspectsGenerator($birth_data);
		$startPeriod = date('Y:m:d',$this->daysInMinus).':12:00:am';
		//$startPeriod = date('Y:m:d',strtotime(date("Y-m-d", mktime()) . " - 365 day")).':12:00:am';
		$endPeriod = date('Y:m:d',strtotime(date("Y-m-d", mktime()) . " + 365 day")).':12:00:am';
		$res = $a->find_aspects($startPeriod, $endPeriod);
		
			$aspect_text = array(	
				0=>"conjunction",
				180 => "opposition",
				60 => "sextile",
				90 => "square",
				270 => "square",
				120 => "trine",
				240 => "trine",
				210 => "aspects"
				);

		$future = array();
		foreach($res as $transitDate => $aspect){
			list($yyyy, $mm, $dd) = split('[:]', $transitDate);

			$yyyy = (int)$yyyy;
			$mm = (int)$mm;
			$dd = (int)$dd;
			$theTime = mktime(0, 0, 0, $mm, $dd, $yyyy);
			$strTransitDate = date("d F Y", $theTime);
			$start = date('Y-m-d',$theTime);
			foreach($aspect as $aspect_index => $aval){
				$skip = array('Neptune', 'Pluto', 'Uranus');
				if(in_array($aval[0],$skip) || in_array($aval[1],$skip))
					continue;

				if($aval[2] == 0 && $aval[0] == 'Ketu' && ($aval[1] == 'Ketu' || $aval[1] == 'Rahu'))
					continue;

				if($aval[2] == 180 && ($aval[1] == 'Ketu' || $aval[1] == 'Rahu'))
					continue;
					
				//if($aval[3]){ $thetitle = $aval[3]; }else{ $thetitle = $aval[0] . ' - ' . $aval[1]; }
				
				$thisAspect = $aspect_text[ $aval[2] ];
				$thetitle = "$aval[0] $thisAspect $aval[1], Exact: $strTransitDate";
				
				$title = 'Transit alert for '.$birth_data['report_name'].' : '. $thetitle;
				//Transit alert for “chart name”: Jupiter Trine Saturn, Exact: 12 June 2016.
				$dataArr = array('title'	=> $title);
				$data = serialize($dataArr);
				$insertSql = "insert into $tbl_future_schedule (bchartid,sdate,data) values (\"$pid\",\"$start\",'".$data."');";
				$wpdb->query($insertSql);
				
			} 
		}		
	}
	
	function getInsertSqlDasha($pid,$dashasince,$theload,$birthdata){
		global $wpdb, $table_prefix,$tbl_future_schedule;
		for($i=0;$i<count($dashasince);$i++){
			$subperiod = $dashasince[$i]['subperiod'];
			if($subperiod){
				$theload[] =  $dashasince[$i]['dashaLord'];					
				self::getInsertSqlDasha($pid, $subperiod,$theload,$birthdata);
			}else{ //INSERT
				$load = $dashasince[$i]['dashaLord'];
				$start = $dashasince[$i]['startDate'];
				$end = $dashasince[$i]['endDate'];
				$starttime = strtotime($start);
				$endtime = strtotime($end);
				$start = date('d F Y',$starttime);
				$end = date('d F Y',$endtime);
				$theloadStr = '';
				if($theload){ $theloadStr = implode(' - ',$theload); }
				$title = 'Dasha alert for '.$birthdata['report_name'].' : '.$theloadStr.' - '.$load.', '.$start.' to '.$end;
				//Dasha alert for “chart name” : Rahu - Ketu - Saturn - Jupiter, 12 July 2016 to 15 August 2017
				if($starttime<$this->daysInAdvance){
					$starttime = $this->daysInAdvance;
				}
				$start = date('Y-m-d',$starttime);
				$dataArr = array('title'	=> $title);
				$data = serialize($dataArr);
				$insertSql = "insert into $tbl_future_schedule (bchartid,sdate,data) values (\"$pid\",\"$start\",'".$data."');";
				$wpdb->query($insertSql);
			}
		}		
	}
			
	function future_notification_schedule() {
		//if (!wp_next_scheduled('future_notification_event')) {
			wp_schedule_event( time(), 'daily', 'future_notification_event' ); //daily,hourly,weekly			
		//}		
	}
	
	function future_notification_schedule_deactivation() {
		wp_clear_scheduled_hook('future_notification_event');
	}
	
	function send_future_notification() {
		// do something every hour
		global $wpdb, $table_prefix;
		$tbl_future_schedule = $table_prefix.'future_schedule';
		
		$schedule_date = date('Y-m-d', $this->daysInAdvance);
		$res = $wpdb->get_results("select * from $tbl_future_schedule where sdate=\"$schedule_date\" and status=1");
		//$res = $wpdb->get_results("select * from $tbl_future_schedule");
		$finalData = array();
		$userIds = array();
		if($res){
			foreach($res as $resobj){
				$bpid = $resobj->bchartid;
				$data = $resobj->data;
				$dataArr = unserialize($data);
				//$title = $dataArr['title'];
				$theData = array();
				$theData['fsid'] = $resobj->fsid;
				$theData['birth_post_id'] = $bpid;
				$theData['data'] = $dataArr;
				$finalData[] = $theData;
				$userIds[] = $bpid;
			}
		}
		$userPostData = array();
		if($userIds){
			$userIdsStr = implode(',',$userIds);
			$postUserSql = "select ID,post_author from $wpdb->posts where ID in ($userIdsStr)";
			$postres = $wpdb->get_results($postUserSql);
			if($postres){
				foreach($postres as $postresObj){
					$post_author_id = $postresObj->post_author;
					$pid = $postresObj->ID;
					$user = new BP_Core_User($post_author_id);
					$name = $user->fullname;
					$email = $user->email;
					$user_url = $user->user_url;
					$userPostData[$pid] = array(
						'userid'	=> $post_author_id,
						'username'	=> $name,
						'email'	=> $email,
						'user_url'	=> $user_url,
					);
				}
			}
		}
		
		$emailSentIds = array();
		if($finalData){
			for($b=0;$b<count($finalData);$b++){
				$fsid = $finalData[$b]['fsid'];
				$emailSentIds[] = $fsid;
				$birth_post_id = $finalData[$b]['birth_post_id'];
				$title = $finalData[$b]['data']['title'];
				$user_id = $userPostData[$birth_post_id]['userid'];
				
				$user_id = $_GET['user_id'];
				
				$args = array(
							'item_id'			=>	$fsid,
							'user_id'			=>	$user_id,
							'action'			=>	'future_prediction',
							'secondary_item_id'	=>	0,
						);
				
				self::send_notification($args);				
			}			
			if($emailSentIds){
				$emailSentIdsStr = implode(', ',$emailSentIds);
				$statusSql = "update $tbl_future_schedule set status=0 where fsid in ($emailSentIdsStr)";
				$wpdb->query($statusSql);
			}
			//print_r($emailSentIds);exit;			
		}
	}
	
	/*************************************************
	Register Buddpress future prediction component
	*************************************************/
	function notifications_set_components( $component_names = array() )
	{
		 // Force $component_names to be an array
		 if ( ! is_array( $component_names ) ) {$component_names = array();}

		 // Add 'futures' component to registered components array
		 array_push( $component_names, 'futures' );
		
		 // Return component's with 'futures' appended	
		 return $component_names;
	}
	
	/*************************************************
	Set voter buddypress componant Global
	*************************************************/
	function future_setup_globals()
	{
		global $bp;
		//for ask oracle.com
		$bp->futures = new BP_Component;
		$bp->futures->notification_callback = 'az_future_schedule::notification_title_format';
		$bp->active_components['futures'] = '1';
	}
	
	/*************************************************
	Get user's voting details
	*************************************************/
	function notification_title_format( $component_action, $item_id, $secondary_item_id )
	{
		if($component_action=='futures' || $component_action=='future_prediction'){
			global $wpdb, $table_prefix;
			$notification = '';
			$tbl_future_schedule = $table_prefix.'future_schedule';
			$data = $wpdb->get_var("select data from $tbl_future_schedule where fsid=\"$item_id\"");
			if($data){
				$dataArr = unserialize($data);
				$notification = $dataArr['title'];
				//$notification = 'HELLO the future notification.';
			}
			return $notification;
		}
	}
	
	function send_notification($args)
	{
		/**Send Notification start**/
		//$result = bp_core_add_notification($args['item_id'], $args['user_id'], 'futures', $args['action']);
		/**Send Notification start**/
		if(function_exists('bp_notifications_add_notification')){
			$result = bp_notifications_add_notification($args['item_id'], $args['user_id'], 'futures', $args['action']);
		}else{
			$result = bp_core_add_notification($args['item_id'], $args['user_id'], 'futures', $args['action']);
		}
		
	}
}

$GLOBALS['azfps'] = az_future_schedule::getInstance();

add_filter('bp_notifications_get_registered_components', array('az_future_schedule','notifications_set_components'),10);
add_action('bp_setup_globals', array('az_future_schedule','future_setup_globals'),999);
add_filter('bp_notifications_get_notifications_for_user',array('az_future_schedule','notification_title_format'),'',4);