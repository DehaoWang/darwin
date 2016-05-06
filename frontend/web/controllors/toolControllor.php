<?PHP
//测试类

class toolControllor extends baseControllor{
	private $csv_type_list = array( 'replace'=>'覆盖数据','add'=> '追加数据', 'update'=>'更新数据' );
	private $_tablename;
	private $_dis_actions;
	private $_action_config;
	private $_children_id;
	private $page_num = 40;		// 分页数

	public function __construct(){

		global $_REQ;
		parent::__construct();
		// 数据库链接
		if (!isset($GLOBALS['p2p_db'])) {
			$dbconf = $GLOBALS['conf']['DB_ARRAYS']['firstp2p'];
			define('DB_PREFIX', $dbconf['DB_PREFIX']);
			$GLOBALS['p2p_db'] = new MysqlDb($dbconf['DB_HOST'] . ":" . $dbconf['DB_PORT'],
					$dbconf['DB_USER'],
					$dbconf['DB_PSWD'],
					$dbconf['DB_NAME'],
					'utf8');
		}
		if (!isset($GLOBALS['db'])) {
			$dbconf = $GLOBALS['conf']['DB_ARRAYS']['log'];
			$GLOBALS['db'] = new MysqlDb($dbconf['DB_HOST'] . ":" . $dbconf['DB_PORT'],
					$dbconf['DB_USER'],
					$dbconf['DB_PSWD'],
					$dbconf['DB_NAME'],
					'utf8');
		}
		
		if (!isset($GLOBALS['darwindb'])) {
			$dbconf = $GLOBALS['conf']['DB_ARRAYS']['darwindb'];
			$GLOBALS['darwindb'] = new MysqlDb($dbconf['DB_HOST'] . ":" . $dbconf['DB_PORT'],
					$dbconf['DB_USER'],
					$dbconf['DB_PSWD'],
					$dbconf['DB_NAME'],
					'utf8');
		}

		T::import('libs.db.mysql#class');
		T::import('libs.genms.token#class');
		T::import('libs.genms.logic#class');

		if (defined('ORI_MODULE_NAME') && defined('ORI_ACTION_NAME')) {
			$this->_children_id = $GLOBALS['conf']['block_id'];
		}

		//
		if (MODULE_NAME=='tool' && in_array(ACTION_NAME, array('report_create','block_create_1','block_create_2','block_create_3','block_create_4'))) {
			define('ORI_MODULE_NAME', 'tool');
			if (ACTION_NAME == 'report_create') {
				define('ORI_ACTION_NAME', 'report_list');
			} else {
				define('ORI_ACTION_NAME', 'block_list');
			}
		}
		// 数据块预览时的菜单选中
		if (MODULE_NAME=='tool' && ACTION_NAME == 'report' && $_REQ['children_id']) {
			define('ORI_MODULE_NAME', 'tool');
			define('ORI_ACTION_NAME', 'block_list');
		}

		$this->assign('status_0', web_url(moDULE_NAME,ACTION_NAME,array_merge($_GET, array('status' => '0'))));
		$this->assign('status_1', web_url(MODULE_NAME,ACTION_NAME,array_merge($_GET, array('status' => '1'))));
		$this->assign('status_2', web_url(MODULE_NAME,ACTION_NAME,array_merge($_GET, array('status' => '2'))));
		$this->assign('status_3', web_url(MODULE_NAME,ACTION_NAME,array_merge($_GET, array('status' => '3'))));

	}

	/**
	 * 自动化报表
	 */
	public function report(){
		session_write_close();
		set_time_limit(300);
		global $_REQ;

		T::import('libs.common.SysCrypt');
		$key='fastAndFurious';


		$_SESSION['task']=$_REQ['task'];
		
		$children_id = !empty($_REQ['children_id']) ? trim($_REQ['children_id']) : '';
		if ($children_id) {
			$this->_children_id = $children_id;
		}




		if (empty($this->_children_id)) {
			exit('报表未配置数据块ID');
		}

		$children_id = $this->_children_id;
		$children_arr = explode(',', $children_id);

		$size=count($children_arr);
		$_SESSION['size']=$size;
		if ($size==1)
		{
			$find_status_sql='select status from ncf_block where id='.$this->_children_id;
			$status=$GLOBALS['config']->getOne($find_status_sql);
			
			$send_json_sql = 'select send_json from ncf_block where id='.$this->_children_id;
			$send_json = $GLOBALS['config']->getOne($send_json_sql);
		}
		
		$send_arr = array();
		
		if ($send_json!=null)
		{
			$send_arr = json_decode($send_json, true);
			$_SESSION['send_arr']=$send_arr['is_send'];
			$this->assign('send_arr', $send_arr);
		}
		
		if ($size==1 && $status==4)
		{
			$user_id=$_SESSION['user_id'];
			//$user_name=$_SESSION['user_name'];
			
			
			//获取此id对应task_id的状态
// 			$field=array('task_id','sql_md5');
// 			$where=array('id'=>$this->_children_id);
// 			$task_id = $GLOBALS['config']->find('ncf_block', $field, $where);
			$field=array('ncf_simple_block_id','sql_md5');
			$where=array('user_id'=>$user_id, 'ncf_block_id'=>$this->_children_id);
			$task_id=$GLOBALS['config']->find('backend_multi_users', $field, $where);


			$first_attemp=count($task_id);
			if ($first_attemp==0 && $_REQ['task']!='task_start')
			{
				$this->assign('fst_attempt', 'fst_attempt');
			}

			//得到此任务上次所得sql的md5值
			$old_sql_md5 = $task_id['sql_md5'];

			$_SESSION['old_sql']=$old_sql_md5;
			//根据task_id获取对应任务的status
			$task_field=array('status');
			$task_where=array('id'=>$task_id['ncf_simple_block_id']);
			$task_status=$GLOBALS['config']->find('ncf_simple_block', $task_field, $task_where);

			$_SESSION['task_status']=$task_status['status'];
		}


		// redis 缓存
		$conf = array();
		if (!empty($GLOBALS['conf']['REDIS_CONF'])) {
			T::import('libs.db.RedisCache#class');
			$conf = $GLOBALS['conf']['REDIS_CONF'];
			// 读写分离
			$redis_r = new RedisCache($conf, 'r');	// 读
			$redis_w = new RedisCache($conf, 'w');	// 写
		}

		$data = array();
		$list = $page = array();
		$filter = $def = $hidden = $table = $set = $tubiao = $tubiao_json = array();
		$action = $page_html = '';
		$backend_data=array();
		foreach ($children_arr as $val) {

			$block = $GLOBALS['config']->find('ncf_block', '', " id={$val} and status in(1,4) ");

			if ($block['status']==4 && $size==1 && $task_id['ncf_simple_block_id'])
			{
				$sql='select from_unixtime(create_time) from ncf_simple_block where id='.$task_id['ncf_simple_block_id'];
				$create_time=$GLOBALS['config']->getOne($sql);
				$this->assign('create_time', $create_time);
			}


			$backend_data=$block;
			if (empty($block)) {
				continue;
			}
			if ($block['status']==4)
			{
				$this->assign('backend', $block['status']);
			}
			$row = $row_list = array();
			$block_filter = json_decode($block['filter_json'], true);

			if (!empty($conf)) {
				if (!empty($block_filter['is_cache'])) {
					// 缓存时间
					$cache_time = $block_filter['cache_time'] ? : 300;
					$redis_key = 'block_'.$block['id'].'_'.date('Y-m-d', time()).$block['update_time'].md5(json_encode($_REQ).md5(json_encode($GLOBALS['conf']['selectors'])));

					// 获取缓存数据
					$content = $redis_r->get($redis_key);
					if($content){
						$row_list = json_decode($content, true);
						if ($row_list['cache_time'] >= (time()-$cache_time)) {
							$row = $row_list['row'];
						}
					}
				} else {
					$keys = $redis_r->keys('block_'.$block['id'].'_*');
					if ($keys) {
						// 如果没缓存，则删除以前的
						foreach ($keys as $v) {
							$redis_w->del($v);
						}
					}
				}
			}
			$new_sql=$this->handle_get_sql($block);
			
			$new_sql_md5 = md5($new_sql);


		
			// 获取实时数据
			if (empty($row) && $block['type'] && $block['script']) {
				if ($block['status']==4 && $size==1)
				{
					if ($_REQ['task']=='task_start' && ($new_sql_md5!=$old_sql_md5 || ($task_status['status'] != 1 && $task_status['status'] != 2)))
					{
						$field=array('sql_md5'=>'');
						$where=array('ncf_block_id'=>$this->_children_id, 'user_id'=>$user_id);
						$GLOBALS['config']->update('backend_multi_users', $field, $where);
						$_SESSION['old_sql']= '';

						$block = $GLOBALS['config']->find('ncf_block', '', " id={$val} and status in(1,4) ");

						$row = $this->handle_get_data($block);
						
						$field=array('status');
						$insrt = $_SESSION['insertId'];
						$_SESSION['insertId']='';
						$where=array('id'=>$insrt);

						$result = $GLOBALS['config']->find('ncf_simple_block', $field, $where);
						$_SESSION['task_status']=$result['status'];

						if ($result['status']==1)
						{
							$result['status']='等待';
						}elseif ($result['status']==2)
						{
							$result['status']='正在运行';
						}elseif ($result['status']==3)
						{
							$result['status']='正常结束';
						}elseif ($result['status']==4)
						{
							$result['status']='异常结束';
						}

					 	$data_en=$task_id['ncf_simple_block_id'];
					 	$url=SysCrypt::encrypt($data_en, $key);
						$this->assign('result', $result['status']);

						$this->assign('download_url', 'http://'.$_SERVER['HTTP_HOST'].'/tool/simpleblockdown?id='.$url);
					}//此else条件用户用户强制刷新
					else
					{
						$row = $this->handle_get_data($block);

						//$refsh_id=$block['id'];

						//$field1=array('task_id');
						//$where1=array('id'=>$refsh_id);

						//$insrt_id = $GLOBALS['config']->find('ncf_block', $field1, $where1);
						$field=array('status');
						//$where=array('id'=>$insrt_id['task_id']);
						$where=array('id'=>$task_id['ncf_simple_block_id']);
						$result = $GLOBALS['config']->find('ncf_simple_block', $field, $where);

						if ($result['status']==1)
						{
							$result['status']='等待';
						}elseif ($result['status']==2)
						{
							$result['status']='正在运行';
						}elseif ($result['status']==3)
						{
							$result['status']='正常结束';
						}elseif ($result['status']==4 || $result['status']==5)
						{
							$result['status']='异常结束';
						}
 						$data_en=$task_id['ncf_simple_block_id'];
 						$url=SysCrypt::encrypt($data_en, $key);
						$this->assign('result', $result['status']);
						$this->assign('download_url', 'http://'.$_SERVER['HTTP_HOST'].'/tool/simpleblockdown?id='.$block['task_id']);
						$this->assign('download_url', 'http://'.$_SERVER['HTTP_HOST'].'/tool/simpleblockdown?id='.$url);
					}
				}else
				{
					$row = $this->handle_get_data($block);
				}


			}
	
			if ($row) {
				// 缓存数据
				if (!empty($block_filter['is_cache']) && !empty($conf)) {
					$row_list['cache_time'] = time();
					$row_list['row'] = $row;
					$redis_w->set($redis_key, json_encode($row_list), $cache_time);
				}
			}

			// 过滤、默认值
			if ($row && $row['filter'] && empty($filter)) {
				$filter = $row['filter'];
				$def = $row['def'];
			}

			// 特殊设置
			if ($block['set_json'] && empty($set)) {
				$set = $this->get_set_list($block);
				$def['select_chart_val'] = (int)$_REQ['select_chart_val'];
			}

			// 分页
			if ($row && $row['page'] && empty($page)) {
				$page = $row['page'];
			}

			// 查询url
			if ($row && $row['action'] && empty($action)) {
				$action = $row['action'];
			}

			// 隐藏值
			if ($row && $row['hidden'] && empty($hidden)) {
				$hidden = $row['hidden'];
			}
			$data[] = $row;
		}

		// 如果是导出
		if ($_REQ['is_export']) {
			$this->handle_export($data);
		}

		if ($_REQ['send_succ']) {
			$this->handle_send($data, $send_arr['send_url'], $block['status'], $_SESSION['user_id'], $this->_children_id);
		}

		$week_list = array();
		if (!empty($filter['week'])) {
			$week_list = $this->get_week_list($_REQ['week']);
		}
		$month_list = array();
		if (!empty($filter['month'])) {
			$month_list = $this->get_month_list($_REQ['month']);
		}
		if (!empty($filter['year'])) {
			$year_list = $this->get_year_list($_REQ['year']);
		}

		$this->assign('week_list', $week_list);

		$this->assign('month_list', $month_list);

		$this->assign('year_list', $year_list);

		$this->assign('csv_type_list', $this->csv_type_list);

		$this->assign('filter', $filter);

		$this->assign('set', $set);

		$this->assign('def', $def);

		if ($backend_data['status']!=4)
		{
			$this->assign('data', $data);
		}
		$this->assign('action', $action);

		$this->assign('hidden', $hidden);

		$this->assign('page', $page);
		
		$this->assign('self_url', $_SERVER['REQUEST_URI']);
		if ($_REQ['task'] == 'task_start') {
			$params = $_SERVER['QUERY_STRING'];

			$count=strpos($params,"&task=task_start");
			$params=substr_replace($params,"",$count,16);

			$data = array('status' => $result['status'], 'params' => $params);

  			$this->out('ok', $data);
		}
	
		$this->display('report');
	}
	
	protected function handle_send($data, $url, $status, $user_id, $ncf_simple_id){
		if ($status==1)
		{
			if (empty($data)) { return false; };
			$list = array();
			$dump = array();
			foreach ($data as $val) {
				$title = array();
				$table_title = $val['table_title'];
				foreach ($table_title as $v) {
					$title[] = $v['desc'];
				}
				$list[] = $title;
				$dump[] = @iconv("UTF-8","GB18030//IGNORE",implode ( ",", $title ));
				foreach ($val['list'] as $v) {
					$tmp = array();
					foreach ($table_title as $i=>$i) {
						$tmp[] = str_replace(',','，',$v[$i]);
					}
					$list[] = $tmp;
					$dump[] = @iconv("UTF-8","GB18030//IGNORE",implode ( ",", $tmp ));
				}
			}
			
			$path_csv = generate_csv($list);
		
			$ch = curl_init ();
			$post_data = array(
					'file' => '@'.$path_csv
			);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch,CURLOPT_BINARYTRANSFER,true);
			curl_setopt($ch, CURLOPT_POSTFIELDS,$post_data);
			curl_setopt($ch, CURLOPT_URL, $url);
			$info= curl_exec($ch);
			curl_close($ch);
		}elseif ($status==4)
		{
			$sql = 'select ncf_simple_block_id from backend_multi_users where user_id='.$user_id.' and ncf_block_id='.$ncf_simple_id.';';
			$res = $GLOBALS['config']->getOne($sql);
			//$path_csv ='/apps/product/nginx/htdocs/liuxd/runtime/simpleblock/simpleblock_'.$res.'.csv';
			$path_csv =APP_RUNTIME_PATH.'simpleblock/simpleblock_'.$res.'.csv';
			$ch = curl_init ();
			$post_data = array(
					'file' => '@'.$path_csv
			);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch,CURLOPT_BINARYTRANSFER,true);
			curl_setopt($ch, CURLOPT_POSTFIELDS,$post_data);
			curl_setopt($ch, CURLOPT_URL, $url);
			$info= curl_exec($ch);
			curl_close($ch);
		}
		exit;
	}
	
	
	
	/**
	 * 自动报表导出
	 * @param array $data
	 * @return boolean
	 */
	protected function handle_export($data){
		if (empty($data)) { return false; };
		$list = array();
		$dump = array();
		foreach ($data as $val) {
			$title = array();
			$table_title = $val['table_title'];
			foreach ($table_title as $v) {
				$title[] = $v['desc'];
			}
			$list[] = $title;
			$dump[] = @iconv("UTF-8","GB18030//IGNORE",implode ( ",", $title ));
			foreach ($val['list'] as $v) {
				$tmp = array();
				foreach ($table_title as $i=>$i) {
					$tmp[] = str_replace(',','，',$v[$i]);
				}
				$list[] = $tmp;
				$dump[] = @iconv("UTF-8","GB18030//IGNORE",implode ( ",", $tmp ));
			}
			// 加个表格 空2行
			$list[] = array();
			$list[] = array();
		}
		// 下载
		//$path_csv = generate_csv($list);
		//file_download($path_csv, 'csv');
		$file_name = sprintf("baize_%s.csv",date("YmdHis", time()));
		$du = implode ( "\n", $dump );
		web_output_file($du, $file_name);

		exit;
	}

	/**
	 * 获取最近10周的列表
	 * @return multitype:string
	 */
	protected function get_week_list($week = ''){
		if (empty($week)) {
			$w_time = time();
			$time = time();
		} else {
			$w_time = strtotime($week);
			$time = time();
		}

		$y = date('Y', $w_time);
		$w = date('W', $w_time);
		$w_time = strtotime($y.'W'.$w);

		$list = array();
		$w_step = 86400*7;
		if (($time - $w_time) <= 86400*49 ) {
			for ($i=0; $i< 10; $i++) {
				$t = strtotime("-{$i} week", $time);
				$w = date('W', $t);
				$y = ($w == '01') ? date('Y', $t+$w_step) : date('Y', $t);
				$list[$y.'W'.$w] = $y.'年'.$w.'周';
			}
			$list = array_reverse($list);
		} else {
			$l_list = $r_list = array();
			for ($i=0; $i< 6; $i++) {
				// 向左
				$t = strtotime("-{$i} week", $w_time);
				$w = date('W', $t);
				$y = ($w == '01') ? date('Y', $t+$w_step) : date('Y', $t);
				$l_list[$y.'W'.$w] = $y.'年'.$w.'周';
				// 向右
				$t = strtotime("+{$i} week", $w_time);
				$w = date('W', $t);
				$y = ($w == '01') ? date('Y', $t+$w_step) : date('Y', $t);
				$r_list[$y.'W'.$w] = $y.'年'.$w.'周';
			}
			$l_list = array_reverse($l_list);
			$list = array_merge($l_list, $r_list);
		}
		array_unshift($list, '请选择');

		return $list;
	}


	/**
	 * 获取最新12个月的列表
	 */
	protected function get_month_list($month = ''){
		if (empty($month)) {
			$m_time = time();
			$time = time();
		} else {
			$m_time = strtotime($month);
			$time = time();
		}

		$y = date('Y', $m_time);
		$m = date('n', $m_time);
		$m_time = strtotime($y.'-'.$m);
		$time = $time + 86400;
		$list = array();

		for ($i=0; $i<10; $i++) {
			if ($m < 10) {
				$m = '0'.$m;
			}
			$list[$y.'-'.$m] = $y.'年'.$m.'月';
			$m--;
			if ($m == 0) {
				$y = ($y - 1);
				$m = 12;
			}
		}
		$list = array_reverse($list);
		array_unshift($list, '请选择');

		return $list;
	}

	/**
	 * 获取最新12个月的列表
	 */
	protected function get_year_list($year = ''){
		if (empty($year)) {
			$year = date('Y', time());
		}
		$time = time();
		$y = date('Y', $time);
		$list = array();
		if (($y - $year) <= 5 ) {
			for ($i=($y-5); $i<=$y; $i++) {
				$list[$i] = $i.'年';
			}
		} else {
			$l_list = $r_list = array();
			for ($i=2; $i>= 1; $i--) {
				// 向左
				$o = (string)$year-$i;
				$list[$o] = $o.'年';
			}
			$list[$year] = $year.'年';
			for ($i=1; $i<= 2; $i++) {
				// 向右
				$o = (string)$year+$i;
				$list[$o] = $o.'年';
			}
		}
		$list[0] = '请选择';
		ksort($list);

		return $list;
	}


	/**
	 * 处理数据块的特殊设置
	 * @param array $block
	 * @return array();
	 */
	protected function get_set_list($block){
		if (empty($block)) {
			return array();
		}
		$set = json_decode($block['set_json'], true);
		if (empty($set)) {
			return array();
		}
		// 下列显示图表
		if (!empty($set['is_select_chart'])) {
			if (!empty($set['select_chart'])) {
				$select_chart_list = explode(',', $set['select_chart']);
			}
			if (empty($select_chart_list)) {
				$chart = json_decode($block['chart_json'], true);
				foreach ($chart as $val) {
					$select_chart_list[] = $val['chart_subtitle'];
				}
			}
		}

		$set['select_chart_list'] = $select_chart_list;
		return $set;
	}


	/**
	 * 根据不同数据引擎来解析,返回固定格式的数组
	 * @param array $block
	 * @return Ambigous <multitype:, string, multitype:multitype:string  multitype:unknown number  >
	 */
	protected function handle_get_data($block){
		// 根据不同数据引擎来解析
		$type = $block['type'];
		$typeclass = $type.'Tool';
		T::import("libs.common.report_tool.{$typeclass}");
		if (class_exists($typeclass)) {
			$handle = new $typeclass($block);
			$row = $handle->get_data();
		} else {
			$row = array();
		}

		return $row;
	}

	protected function handle_get_sql($block){
		if ($block['type']!='mysql')
		{
			return;
		}
		// 根据不同数据引擎来解析
		$type = $block['type'];
		$typeclass = $type.'Tool';
		T::import("libs.common.report_tool.{$typeclass}");
		if (class_exists($typeclass)) {
			$handle = new $typeclass($block);

			$row = $handle->get_sql();


		} else {
			$row = array();
		}
		return $row;
	}

	/**
	 * 数据块列表
	 */
	public function block_list(){

		$_SESSION['task']='';

		global $_REQ;
		$id = !empty($_REQ['id']) ? (int)trim($_REQ['id']) : '';
		$status = isset($_REQ['status']) ? (int)intval($_REQ['status']) : 1;
		$search = !empty($_REQ['search']) ? trim($_REQ['search']) : '';
		$p = !empty($_REQ['p']) ? intval(trim($_REQ['p'])) : 1;

		$def['search_title'] = '标题';
		$def['search'] = $search;
		$def['id'] = $id;
		$def['status'] = $status;

		// 条件
		$where = 'where 1=1 ';
		if ($search) {
			$where .= " and title like '%{$search}%' ";
		}
		if ($id) {
			$where .= " and id={$id} ";
		}
		if ($status) {
			$where .= " and status={$status} ";
		}

		// 获取总数
		$sql = "select count(id) from ncf_block {$where} ";
		$count = $GLOBALS ['config']->getOne ( $sql );
		$page_num = $this->page_num;
		$page_count = ceil( $count / $page_num );
		if ($p <= 1) {
			$p = 1;
		} else if ($p >= $page_count) {
			$p = $page_count;
		}
		$offest = ($p - 1) * $page_num;
		// 获取列表
		$sql = "select * from ncf_block {$where} order by create_time DESC limit {$offest},{$page_num}";
		$list = $GLOBALS ['config']->getAll ( $sql );
		if ($list) {
			foreach ($list as &$val) {
				$val['menu'] = '';
				// 使用菜单
				$wh = "block_id like '%{$val['id']}%'";
				$menu = $GLOBALS['config']->select('rbac_menu', 'id,title,block_id', $wh, 'id ASC');
				foreach ($menu as $v) {
					$url = web_url ( 'admin', 'menu_list', array('id'=>$v['id']) );
					$block_id = explode(',', $v['block_id']);
					if ($block_id) {
						foreach ($block_id as $b) {
							if ($b == $val['id']) {
								$val['menu'] .= "<a href='{$url}' target='_blank' title='{$v['title']}'>{$v['id']}</a>,";
							}
						}
					}
				}
				$val['menu'] = trim($val['menu'], ',');

				if ($val['status'] == 1) {
					$val['status_str'] = '正常';
				} else if ($val['status'] == 2) {
					$val['status_str'] = '<span class="text-warning">草稿</span>';
				} else if ($val['status'] == 4) {
					$val['status_str'] = '<span class="text-warning">后台</span>';
				} else {
					$val['status_str'] = '<span class="text-danger">删除</span>';
				}
				if ($val['update_time']) {
					$val['update_time'] = date('Y-m-d H:i:s', $val['update_time']);
				}

				$val['create_time'] = date('Y-m-d H:i:s', $val['create_time']);
			}
		}

		// 添加的url
		$action = web_url ( MODULE_NAME, 'block_create_1' );
		$this->assign ( 'create_url', $action );
		// 提交查询的url
		$action = web_url ( MODULE_NAME, ACTION_NAME, array('search'=>$search,'p'=>$p) );
		$this->assign ( 'action', $action );

		T::import("libs.ORG.page");
		// 分页
		$page_data = array(
				'baseurl' => $action.'&p=', //string, 基础url, ex.	#?p=
				'maxpage' => $page_count, //int, 最大页数
				'curpage' => $p, //int, 当前页数
		);
		$page = page::pagination($page_data);
		$this->assign('page', $page);

		$fds = array (
				'id' => array( 'desc' => 'ID','width'=>4 ,'class'=>'text-primary f-bold' ),
				'title' => array( 'desc' => '数据块名称' ),
				'intro' => array ( 'desc' => '简介'),
				'menu' => array ( 'desc' => '使用菜单'),
				'type' => array ( 'desc' => '数据引擎','width'=>'70px'),
				'db' => array ( 'desc' => '数据库','width'=>'115px'),
				'update_time' => array ( 'desc' => '更新时间','width'=>'90px'),
				'create_time' => array ( 'desc' => '创建时间','width'=>'90px'),
				'create_user' => array ( 'desc' => '创建人','width'=>'120px'),
				'status_str' => array ( 'desc' => '状态','width'=>4),
		);

		$this->assign('def', $def);
		$this->assign ( 'fds', $fds );
		$this->assign ( 'list', $list );
		$this->display('block_list');
	}
	/**
	 * 删除 加入回收站
	 */
	public function block_delete(){
		global $_REQ;

		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		if (empty($id)) {
			exit(json_encode(array('info' => 'error', 'data' => '请选择要删除的记录！')));
		}

		$data['status'] = 3;
		$result = $GLOBALS['config']->update('ncf_block', $data, "id={$id}");

		if ($result) {
			exit(json_encode(array('info' => 'ok', 'data' => '加入回收站成功')));
		} else {
			exit(json_encode(array('info' => 'error', 'data' => '加入回收站失败')));
		}
	}
	/**
	 * 还原
	 */
	public function block_reduction(){
		global $_REQ;

		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		if (empty($id)) {
			exit(json_encode(array('info' => 'error', 'data' => '请选择要还原的记录！')));
		}
		$data['status'] = 1;
		$result = $GLOBALS['config']->update('ncf_block', $data, "id={$id}");;

		if ($result) {
			exit(json_encode(array('info' => 'ok', 'data' => '还原成功')));
		} else {
			exit(json_encode(array('info' => 'error', 'data' => '还原失败')));
		}
	}
	/**
	 * 彻底删除
	 */
	public function block_delete_really(){
		global $_REQ;

		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		if (empty($id)) {
			exit(json_encode(array('info' => 'error', 'data' => '请选择要彻底删除的记录！')));
		}

		$result = $GLOBALS['config']->del('ncf_block', "id={$id}");

		if ($result) {
			exit(json_encode(array('info' => 'ok', 'data' => '彻底删除成功')));
		} else {
			exit(json_encode(array('info' => 'error', 'data' => '彻底删除失败')));
		}
	}

	/**
	 * 恢复数据块历史设置
	 */
	public function block_reset_history(){
		global $_REQ;
		// 要恢复的数据块id
		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		// 恢复到倒数第几次
		$step = !empty($_REQ['step']) ? intval(trim($_REQ['step'])) : 1;

		if ($step >= 1) {
			$step = ($step - 1);
		}

		$history_list = $GLOBALS['config']->select('ncf_block_history','*', "block_id={$id}", 'update_time desc', "{$step},1");

		if (empty($history_list)) {
			exit(json_encode(array('info' => 'error', 'data' => '没有历史记录')));
		}
		$info = array_shift($history_list);

		unset($info['id']);
		unset($info['block_id']);

		$result = $GLOBALS['config']->update('ncf_block', $info, "id={$id}");

		if ($result) {
			exit(json_encode(array('info' => 'ok', 'data' => '恢复数据块历史成功')));
		} else {
			exit(json_encode(array('info' => 'error', 'data' => '恢复数据块历史失败')));
		}
	}


	protected function get_block_key($id=0){
		return 'block_'.$id;
	}

	/**
	 * 数据块添加第一步
	 */
	public function block_create_1(){
		global $_REQ;
		if (empty($_SESSION['block_0'])) {
			$_SESSION['block_0'] = array();
		}
		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		$block = $this->get_block_key($id);
		if ($id) {
			$info = $GLOBALS['config']->find('ncf_block', '*', "id={$id}");
			$_SESSION[$block] = $info;
		} else {
			$_SESSION[$block]['id'] = null;
			$info = !empty($_SESSION[$block]) ? $_SESSION[$block] : array();
			$info['id'] = null;
		}
		if (empty($id) && $_SESSION[$block]['id']) {
			$_SESSION[$block]['id'] = null;
		}


		// 保存的url
		$action = web_url ( MODULE_NAME, 'block_create_2' );
		$this->assign ( 'action', $action );
		// 下一步的url
		$action = web_url ( MODULE_NAME, 'block_create_2' );
		$this->assign ( 'next_url', $action );

		$this->assign('info', $info);
		$this->display('block_create_1');
	}

	/**
	 * 数据块添加第二步
	 */
	public function block_create_2(){
		// 第一步的数据
		global $_REQ;
		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		$block = $this->get_block_key($id);
		if (empty($id)) {
			$_SESSION[$block]['id'] = null;
		}
		// 如果没有标题、脚本返回第一步
		if (empty($_SESSION[$block]['title']) && empty($_REQ['title'])) {
			web_redirect(web_url ( MODULE_NAME, 'block_create_1', array('id' => $id)));
		}
		$data['id'] = $id;
		$data['title'] = trim($_REQ['title']) ? trim($_REQ['title']) : $_SESSION[$block]['title'];
		$data['intro'] = trim($_REQ['intro']) ? trim($_REQ['intro']) : $_SESSION[$block]['intro'];
		$data['status'] = trim($_REQ['status']) ? trim($_REQ['status']) : $_SESSION[$block]['status'];
		// 把数据块的配置先保存到 session 中
		$_SESSION[$block] = array_merge($_SESSION[$block], $data);

		$filter_json = $_SESSION[$block]['filter_json'];
		if ($filter_json) {
			$filter = json_decode($filter_json, true);
		} else {
			$filter = array();
		}
		$this->assign('filter', $filter);

		// 下拉选择
		$selectors_list = $GLOBALS['config']->select('ncf_user_selectors', 'id,title,group_key', 'status=1', 'id ASC');
		$this->assign('selectors_list', $selectors_list);

		
		// 数据库配置的名称
		$sql = 'show tables';
// 		p($GLOBALS['conf']['DB_ARRAYS']);
// 		exit;
		$result = $GLOBALS['darwindb']->getAll($sql);

		$table_list = array();
		foreach ($result as $val) {
			$table = $val['Tables_in_darwindb'];
			if (strpos($table, 'csv_') === 0) {
				$table_list[] = $table;
			}
		}
		$this->assign('table_list', $table_list);

		// 保存的url
		$action = web_url ( MODULE_NAME, 'block_save_2' );
		$this->assign ( 'action', $action );

		// 数据库配置的名称
		$db_list = array_keys($GLOBALS['conf']['DB_ARRAYS']);
		$this->assign('db_list', $db_list);

		$this->assign('info', $_SESSION[$block]);
		$this->assign('csv_type_list', $this->csv_type_list);
		$this->display('block_create_2');
	}

	public function block_create_3(){
		set_time_limit(0);
		// 第一步的数据
		global $_REQ;
		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		$block = $this->get_block_key($id);

		$data = $_SESSION[$block];

		$field_json = !empty($data['field_json']) ? $data['field_json'] : array();
		if ($field_json) {
			$field = json_decode($field_json, true);
		}
		// 如果没有标题、脚本返回第一步
		if (empty($data['title']) || empty($data['type']) || empty($data['script'])) {
			web_redirect(web_url ( MODULE_NAME, 'block_create_1', array('id' => $id)));
		}
		if ($data['type'] == 'mysql') {
			if (empty($data['db'])) {
				web_redirect(web_url ( MODULE_NAME, 'block_create_1', array('id' => $id)), 0, '请选择要连接的数据库');
			}
		}

		// 根据不同数据引擎来解析
		$type = $data['type'];
		$typeclass = $type.'Tool';
		T::import("libs.common.report_tool.{$typeclass}");
		if (class_exists($typeclass)) {
			$handle = new $typeclass($data);
			$field_list = $handle->explain($data);
		} else {
			$field_list = array();
		}

		// 第四步的url
		$action = web_url ( MODULE_NAME, 'block_create_4' );
		$this->assign ( 'action', $action );

		$set = json_decode($data['set_json'], true);
		$this->assign('set', $set);
		$_SESSION[$block]['field_list'] = $field_list;
		$this->assign('field_list', $field_list);
		$this->assign('field', $field);
		$this->assign('info', $data);
		$this->display('block_create_3');
	}


	public function block_create_4(){
		// 第一步的数据
		global $_REQ;
		$id = !empty($_REQ['id']) ? $_REQ['id'] : 0;
		$block = $this->get_block_key($id);
		$field = array();

		foreach ($_REQ['field'] as $key=>$val) {
			if ($val) {
				$field[$key]['desc'] = urlencode($val);
				$field[$key]['func'] = ($_REQ['func'][$key]);
				$field[$key]['remark'] = ($_REQ['remark'][$key]);
				$field[$key]['table_sort'] = ($_REQ['table_sort'][$key]);
			}
		}
		if (empty($field)) {
			foreach ($_REQ['field'] as $key=>$val) {
				$field[$key]['desc'] = urlencode($key);
				$field[$key]['func'] = ($_REQ['func'][$key]);
				$field[$key]['remark'] = ($_REQ['remark'][$key]);
				$field[$key]['table_sort'] = ($_REQ['table_sort'][$key]);
			}
		}
		if ($field) {
			$_SESSION[$block]['field_json'] = urldecode(json_encode($field));
			$_SESSION[$block]['field_list'] = json_decode(urldecode(json_encode($field)), true);
		}

		$data = $_SESSION[$block];

		$set = json_decode($data['set_json'], true);
		$set['is_table_total'] = $_REQ['is_table_total'];
		$set['is_table_left'] = $_REQ['is_table_left'];
		$set['table_left'] = $_REQ['table_left'];
		$_SESSION[$block]['set_list'] = $set;
		$_SESSION[$block]['set_json'] = json_encode($set);



		// 如果没有标题、脚本返回第一步
		if (empty($data['title']) || empty($data['type']) || empty($data['script'])) {
			web_redirect(web_url ( MODULE_NAME, 'block_create_1', array('id' => $id)));
		}
		// 如果没有数据引擎、脚本返回第二步
		if (empty($data['type']) || empty($data['script'])) {
			web_redirect(web_url ( MODULE_NAME, 'block_create_2', array('id' => $id)));
		}

		$table = $chart = array();
		if ($data) {
			$table = json_decode($data['table_json'], true);
			$set = json_decode($data['set_json'], true);
			$chart = json_decode($data['chart_json'], true);
			$send = json_decode($data['send_json'], true);
		}
		if (empty($chart)) {
			$chart['chart_series'] = array();
		}
		// 最后一步保存的url
		$action = web_url ( MODULE_NAME, 'block_save' );
		$this->assign ( 'action', $action );

		$this->assign('field_list', $_SESSION[$block]['field_list']);
		$this->assign('info', $data);
		$this->assign('table', $table);
		$this->assign('set', $set);
		$this->assign('chart', $chart);
		$this->assign('send', $send);
		$this->display('block_create_4');
	}


	/**
	 * 检测数据	第二步和保存草稿会用到
	 */
	private function check_data(){
		global $_REQ;

		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		$block = $this->get_block_key($id);

		if (empty($_REQ['type'])) {
			exit(json_encode(array('info' => 'error', 'data' => '请选择数据引擎')));
		}
		if (empty($_REQ['script'])) {
			exit(json_encode(array('info' => 'error', 'data' => '请输入脚本数据')));
		}
		if ($_REQ['type'] == 'mysql') {
			if (empty($_REQ['db'])) {
				exit(json_encode(array('info' => 'error', 'data' => '请选择要连接的数据库')));
			}
			$sql = trim($_REQ['script']);
			$sql = stripslashes($sql);
			$sql = preg_replace('/\s{1,}/', ' ', $sql);
			// 限制只能使用 select
			$select = substr($sql, 0, stripos($sql, ' from '));
			$select_arr = explode(' ', $select);
			if (strtolower(array_shift($select_arr)) != 'select') {
				exit(json_encode(array('info' => 'error', 'data' => 'SQL 错误：只能使用select语句')));
			}
		}

		$data['id'] = $id;
		$data['title'] = $_SESSION[$block]['title'];
		$data['intro'] = $_SESSION[$block]['intro'];
		$data['db'] = trim($_REQ['db']);
		$data['type'] = trim($_REQ['type']);
		$data['script'] = trim($_REQ['script']);
		$data['status'] = trim($_REQ['status']);

		// 过滤条件
		$filter['second_two'] = trim($_REQ['second_two']);
		$filter['second_two_def'] = trim($_REQ['second_two_def']);
		$filter['second_two_diff'] = trim($_REQ['second_two_diff']);

		$filter['minute_two'] = trim($_REQ['minute_two']);
		$filter['minute_two_def'] = trim($_REQ['minute_two_def']);
		$filter['minute_two_diff'] = trim($_REQ['minute_two_diff']);

		$filter['date_one'] = trim($_REQ['date_one']);
		$filter['date_one_def'] = trim($_REQ['date_one_def']);
		$filter['date_two'] = trim($_REQ['date_two']);
		$filter['date_two_def'] = trim($_REQ['date_two_def']);
		$filter['date_two_diff'] = trim($_REQ['date_two_diff']);
		$filter['week'] = trim($_REQ['week']);
		$filter['week_def'] = trim($_REQ['week_def']);
		$filter['month'] = trim($_REQ['month']);
		$filter['month_def'] = trim($_REQ['month_def']);
		$filter['year'] = trim($_REQ['year']);
		$filter['year_def'] = trim($_REQ['year_def']);
		// 文件选择
		$filter['is_file'] = $_REQ['is_file'];
		$filter['file_path'] = $_REQ['file_path'];
		// csv to mysql
		$filter['is_csvmysql'] = $_REQ['is_csvmysql'];
		$filter['csvmysql_table'] = $_REQ['csvmysql_table'];
		$filter['csvmysql_type'] = $_REQ['csvmysql_type'];
		$filter['csvmysql_update_key'] = $_REQ['csvmysql_update_key'];
		// 下拉选择
		$filter['is_group'] = trim($_REQ['is_group']);
		$filter['group_id'] = (array)$_REQ['group_id'];
		if ($_REQ['group_from']) {
			$len = count($_REQ['group_from']);
			for ($i=0; $i<$len; $i++) {
				$arr['group_db'] = $_REQ['group_db'][$i];
				$arr['group_from'] = urlencode($_REQ['group_from'][$i]);
				$arr['group_key'] = $_REQ['group_key'][$i];

				$filter['group_list'][] = $arr;
			}
		}

		// 指定筛选
		$filter['search'] = trim($_REQ['search']);
		if ($_REQ['search_title']) {
			$len = count($_REQ['search_title']);
			for ($i=0; $i<$len; $i++) {
				$tmp['search_title'] = urlencode($_REQ['search_title'][$i]);
				$tmp['search_key'] = $_REQ['search_key'][$i];
				$tmp['search_def'] = urlencode($_REQ['search_def'][$i]);

				$filter['search_list'][] = $tmp;
			}
		}
		
		$filter['is_export'] = trim($_REQ['is_export']);
		$filter['export_page'] = trim($_REQ['export_page']);
		$filter['export_url'] = trim($_REQ['export_url']);
		$filter['is_page'] = trim($_REQ['is_page']);
		$filter['page_num'] = trim($_REQ['page_num']);
		$filter['is_cache'] = trim($_REQ['is_cache']);
		$filter['cache_time'] = intval(trim($_REQ['cache_time']));
		
		$data['filter_json'] = urldecode(json_encode($filter));
		
		

		return $data;
	}

	/**
	 * 第二步 或 草稿保存
	 */
	public function block_save_2(){
		global $_REQ;

		$data = $this->check_data();
		$id = $data['id'];
		$block = $this->get_block_key($id);
		$is_draft = !empty($_REQ['is_draft']) ? intval(trim($_REQ['is_draft'])) : 0;

		if ($is_draft) {
			if ($id) {
				// 保存下历史数据
				$history_info = $GLOBALS['config']->find('ncf_block', '*', "id={$id}");
				unset($history_info['id']);
				
				$history_info['block_id'] = $id;
				$result = $GLOBALS['config']->insert('ncf_block_history', $history_info);
				// 更新
				$data['update_time'] = time();
				$data['update_user'] = $_SESSION['user_name'];


				$result = $GLOBALS['config']->update('ncf_block', $data, "id={$id}");
			} else {
				$data['create_time'] = time();
				$data['create_user'] = $_SESSION['user_name'];
				$result = $GLOBALS['config']->insert('ncf_block', $data);
				$id = $result;
				$data['id'] = $result;
			}

			// 把数据块的配置先保存到 session 中
			$_SESSION[$block] = array_merge($_SESSION[$block], $data);
			if ($result) {
				if ($_REQ['isajax']) {
					$url = web_url ( MODULE_NAME, 'block_create_2', array('id' => $id));
					exit(json_encode(array('info' => 'ok', 'data' => $url)));
				} else {
					web_redirect(web_url ( MODULE_NAME, 'block_create_2', array('id' => $id)));
				}
			} else {
				exit(json_encode(array('info' => 'error', 'data' => '保存失败')));
			}
		}
		// 把数据块的配置先保存到 session 中
		$_SESSION[$block] = array_merge($_SESSION[$block], $data);

		if ($_REQ['isajax']) {
			$url = web_url ( MODULE_NAME, 'block_create_3', array('id' => $id));
			exit(json_encode(array('info' => 'ok', 'data' => $url)));
		} else {
			web_redirect(web_url ( MODULE_NAME, 'block_create_3', array('id' => $id)));
		}
	}


	public function block_save(){
		global $_REQ;
		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		$block = $this->get_block_key($id);
		// 表格
		$table['is_table'] = trim($_REQ['is_table']);
		$table['table_sort'] = trim($_REQ['table_sort']);
		$table['table_tpl'] = trim($_REQ['table_tpl']);
		$_SESSION[$block]['table_list'] = $table;
		$_SESSION[$block]['table_json'] = json_encode($table);
		
		//发送
		$send['is_send'] = trim($_REQ['is_send']);
		$send['send_url'] = trim($_REQ['send_url']);
		$_SESSION[$block]['send_list'] = $send;
		$_SESSION[$block]['send_json'] = json_encode($send);
		
		// 特殊设置set
		$set = $_SESSION[$block]['set_list'];
		$set['is_json'] = (int)$_REQ['is_json'];
		$set['is_select_chart'] = $_REQ['is_select_chart'];
		$select_chart = trim($_REQ['select_chart']);
		$set['select_chart'] = str_replace('，',',', $select_chart);
		$_SESSION[$block]['set_list'] = $set;
		$_SESSION[$block]['set_json'] = json_encode($set);

		$field_list = json_decode($_SESSION[$block]['field_json'],true);
		// 图表
		$chart = array();

		if ($_REQ['is_chart']) {
			foreach ($_REQ['is_chart'] as $key=>$val) {
				$tmp = array();
				$tmp['is_chart'] = $_REQ['is_chart'][$key];
				$tmp['chart_type'] = $_REQ['chart_type'][$key];
				$tmp['chart_sort'] = $_REQ['chart_sort'][$key];
				$tmp['chart_height'] = $_REQ['chart_height'][$key];
				$tmp['chart_title'] = urlencode($_REQ['chart_title'][$key]);
				$tmp['chart_subtitle'] = urlencode($_REQ['chart_subtitle'][$key]);
				$tmp['chart_yaxis'] = urlencode($_REQ['chart_yaxis'][$key]);
				$tmp['chart_categories'] = $_REQ['chart_categories'][$key];
				$tmp['settime'] = $_REQ['settime'][$key];
				$tmp['ajaxurl'] = $_REQ['ajaxurl'][$key];
				$tmp['is_show_num'] = $_REQ['is_show_num'][$key];
				$tmp['is_show_x'] = $_REQ['is_show_x'][$key];
				$tmp['is_show_legend'] = $_REQ['is_show_legend'][$key];
				$tmp['chart_group'] = $_REQ['chart_group'][$key];
				$tmp['chart_source'] = $_REQ['chart_source'][$key];

				$series = $_REQ['chart_series'][$key];
				$subtitle = array();
				if ($series) {
					foreach ($series as $k=>$v) {
						if (empty($v)) {
							unset($series[$k]);
						} else {
							$subtitle[] = $field_list[$v]['desc'];
						}
					}
					unset($v);
				}
				$tmp['chart_series'] = $series;
				if (empty($tmp['chart_title'])) {
					$tmp['chart_title'] = $_SESSION[$block]['title'];
				}
				if (empty($tmp['chart_subtitle']) && $subtitle) {
					$tmp['chart_subtitle'] = trim(implode('、',$subtitle), '、');
				}

				$chart[] = $tmp;
			}
			// 排序
			$len = count($chart);
			for ($i = 0; $i < $len; $i++) {
				for ($j = $len-1; $j > $i; $j--) {
					if ($chart[$j]['chart_sort'] < $chart[$j-1]['chart_sort']) {     // 从小到大排序
						$arr = $chart[$j];
						$chart[$j] = $chart[$j-1];
						$chart[$j-1] = $arr;
					}
				}
			}
		}

		$_SESSION[$block]['chart_list'] = $chart;
		$_SESSION[$block]['chart_json'] = urldecode(json_encode($chart));

		$data['title'] = $_SESSION[$block]['title'];
		$data['type'] = $_SESSION[$block]['type'];
		$data['intro'] = $_SESSION[$block]['intro'];
		$data['db'] = $_SESSION[$block]['db'];
		$data['script'] = $_SESSION[$block]['script'];
		$data['field_json'] = $_SESSION[$block]['field_json'];
		$data['filter_json'] = $_SESSION[$block]['filter_json'];
		$data['table_json'] = $_SESSION[$block]['table_json'];
		$data['set_json'] = $_SESSION[$block]['set_json'];
		$data['chart_json'] = $_SESSION[$block]['chart_json'];
		$data['status'] = $_SESSION[$block]['status'];
		$data['send_json'] = $_SESSION[$block]['send_json'];
		if ($id) {
			// 保存下历史数据
			$history_info = $GLOBALS['config']->find('ncf_block', '*', "id={$id}");
			unset($history_info['id']);
			$history_info['block_id'] = $id;
// 			p($history_info['send_json']);
// 			exit;
			$result = $GLOBALS['config']->insert('ncf_block_history', $history_info);
			// 更新
			$data['update_time'] = time();
			$data['update_user'] = $_SESSION['user_name'];
			//p($data);

			$result = $GLOBALS['config']->update('ncf_block', $data, "id={$id}");

		} else {
			$data['create_time'] = time();
			$data['create_user'] = $_SESSION['user_name'];

			$result = $GLOBALS['config']->insert('ncf_block', $data);
			$id = $result;
			$data['id'] = $result;
		}
		// 把数据块的配置先保存到 session 中
		$_SESSION[$block] = array_merge($_SESSION[$block], $data);

		if ($result) {
			if ($_REQ['isajax']) {
				exit(json_encode(array('info' => 'ok', 'data' => $id)));
			} else {
				web_redirect(web_url ( MODULE_NAME, 'block_list'));
			}
		} else {
			exit(json_encode(array('info' => 'error', 'data' => $id)));
		}

	}

	public function block_copy(){
		global $_REQ;
		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;

		$data = $GLOBALS['config']->find('ncf_block', '*', "id={$id}");
		if (empty($data)) {
			$this->out('error', '请指定要复制的数据源');
		}

		unset($data['id']);
		unset($data['create_time']);
		unset($data['create_user']);
		unset($data['update_time']);
		unset($data['update_user']);

		$data['create_time'] = time();
		$data['create_user'] = $_SESSION['user_name'];

		$result = $GLOBALS['config']->insert('ncf_block', $data);

		if ($result) {
			$this->out('ok', '复制成功');
		} else {
			$this->out('error', '复制失败');
		}

	}



	/**
	 * 图片上传方法
	 * @param string $my_file
	 * @param int $a_id
	 */
	public function file_up() {
		$my_file = web_req('my_file', '');
		if (empty ( $my_file )) {
			$this->out ( 'error', '请正确上传文件' );
		}
		$up_info = $_FILES[$my_file];
		// 手动限定的文件最大值
		$maxsize = 10000000;
		// 准许的mimi类型
		$type_arr = array (
				'application/x-tex',
				'application/vnd.ms-excel',
				'application/vnd.ms-powerpoint',
				'application/octet-stream',
				'application/msword',
				'application/xml',
				'application/xhtml+xml',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/force-download',
				'image/jpeg',
				'image/pjpeg',
				'image/jpg',
				'image/png',
				'image/x-png'
		);
		if ($up_info ['size'] > $maxsize) {
			$this->out ( 'error', '上传文件超出上传允许值,最大为' . to_size ( $maxsize ) );
		}
		if (! in_array ( $up_info['type'], $type_arr )) {
			$this->out ( 'error', '上传文件类型不对 '.$up_info['type'] );
		}
		// 移动上传文件
		if (is_uploaded_file ( $up_info['tmp_name'] )) {
			// 算出图片路径
			$savepath = APP_ROOT_PATH .'/' ;
			$uploadpath = $GLOBALS['conf']['UPLOAD_PATH'];
			$dir =  $uploadpath . '/' . date('Ymd', time());
			$path = $savepath . $dir . "/";
			if (! file_exists ( $path )) {
				mkdir ( $path, 0777 , true);
			}
			$p_name = $up_info['name'];
			$pathinfo = $path . $p_name;
			if (move_uploaded_file ( $up_info ['tmp_name'], $pathinfo )) {
				$row["file_name"] = $p_name;
				$row["file_path"] = $dir.'/'. $p_name;
				$row['type'] = $up_info['type'];
				$row['user_id'] = $_SESSION['user_id'];
				$row['user_name'] = $_SESSION['user_name'];
				if (MODULE_NAME == 'tool' && ACTION_NAME == 'file_up') {
					$row['upload_url'] = $_SERVER['HTTP_REFERER'];
				} else {
					$row['upload_url'] = web_url(MODULE_NAME, ACTION_NAME, web_req());
				}
				$row['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
				$row['ip'] = get_client_ip();
				$row['create_time'] = time();
				$GLOBALS['config']->insert('bz_upload', $row);
				$this->out ( 'ok', $row );
			} else {
				$this->out ( 'error', '文件未上传成功' );
			}
		} else {
			$this->out ( 'error', '请正确上传文件' );
		}
	}

	public function query_list(){
		global $_REQ;
		$id = !empty($_REQ['id']) ? (int)trim($_REQ['id']) : '';
		$keyword = !empty($_REQ['keyword']) ? trim($_REQ['keyword']) : '';
		$user = !empty($_REQ['user']) ? trim($_REQ['user']) : '';
		$p = !empty($_REQ['p']) ? intval(trim($_REQ['p'])) : 1;

		$def['search'] = array(
			array(
					'title' => 'ID',
					'key' => 'id',
					'value' => $id,
			),
			array(
					'title' => '标题',
					'key' => 'keyword',
					'value' => $keyword,
			),
			array(
					'title' => '创建人',
					'key' => 'user',
					'value' => $user,
			),
		);
		// 条件
		$where = 'where 1=1 ';
		if ($keyword) {
			$where .= " and title like '%{$keyword}%' ";
		}
		if ($id) {
			$where .= " and id={$id} ";
		}
		if ($user) {
			$where .= " and create_user like '%{$user}%' ";
		}
		if (!$this->isAuth()) {
			$where .= " and create_user = '{$_SESSION['user_name']}'";
		}

		// 获取总数
		$sql = "select count(id) from ncf_query {$where} ";
		$count = $GLOBALS ['config']->getOne ( $sql );

		$page_num = $this->page_num;
		$page_count = ceil( $count / $page_num );
		if ($p <= 1) {
			$p = 1;
		} else if ($p >= $page_count) {
			$p = $page_count;
		}
		$offest = ($p - 1) * $page_num;
		// 获取列表
		$sql = "select * from ncf_query {$where} order by create_time DESC limit {$offest},{$page_num}";
		$list = $GLOBALS ['config']->getAll ( $sql );
		if ($list) {
			foreach ($list as &$val) {
				if ($val['status'] == 1) {
					$val['status_str'] = '正常';
				} else if ($val['status'] == 1) {
					$val['status_str'] = '<span class="text-waring">草稿</span>';
				} else {
					$val['status_str'] = '<span class="text-danger">删除</span>';
				}
				if ($val['update_time']) {
					$val['update_time'] = date('Y-m-d H:i:s', $val['update_time']);
				}
				$val['query'] = substr(stripslashes($val['script']), 0, 100);
				$val['create_time'] = date('Y-m-d H:i:s', $val['create_time']);
				// 编辑的url
				$val['show_oper']['edit_url'] = web_url ( MODULE_NAME, 'query_data', array('id' => $val['id']));
				// 删除的url
				$val['show_oper']['delete_url'] = web_url ( MODULE_NAME, 'query_delete', array('id' => $val['id']));

			}
		}

		// 添加的url
		$action = web_url ( MODULE_NAME, 'query_data' );
		$this->assign ( 'create_url', $action );
		// 提交查询的url
		$action = web_url ( MODULE_NAME, ACTION_NAME, array('keyword'=>$keyword,'p'=>$p) );
		$this->assign ( 'action', $action );
		// 分页
		T::import('libs.ORG.page');
		$page_data = array(
				'baseurl' => $action.'&p=', //string, 基础url, ex.	#?p=
				'maxpage' => $page_count, //int, 最大页数
				'curpage' => $p, //int, 当前页数
		);
		$page = page::pagination($page_data);
		$this->assign('page', $page);

		$fds = array (
				'id' => array( 'desc' => 'ID','width'=>4 ,'class'=>'text-primary f-bold' ),
				'title' => array( 'desc' => '查询名称' ),
				'type' => array ( 'desc' => '数据引擎','width'=>'70px'),
				'db' => array ( 'desc' => '数据库','width'=>'115px'),
				'query' => array ( 'desc' => '查询代码'),
				'update_time' => array ( 'desc' => '更新时间','width'=>'90px'),
				'create_time' => array ( 'desc' => '创建时间','width'=>'90px'),
				'create_user' => array ( 'desc' => '创建人','width'=>'120px'),
				'status_str' => array ( 'desc' => '状态','width'=>4),
		);

		$this->assign('def', $def);
		$this->assign ( 'fds', $fds );
		$this->assign ( 'list', $list );
		$this->display('list_tmp', 'admin');
	}


	public function query_data(){
		global $_REQ;
		if (empty($_SESSION['query_data'])) {
			$_SESSION['query_data'] = array();
		}
		$id = !empty($_REQ['id']) ? intval(trim($_REQ['id'])) : 0;
		if ($id) {
			$info = $GLOBALS['config']->find('ncf_query', '*', "id={$id}");
			$_SESSION['query_data'] = $info;
		} else {
			$_SESSION['query_data']['id'] = null;
			$info = !empty($_SESSION['query_data']) ? $_SESSION['query_data'] : array();
			$info['id'] = null;
		}
		if (empty($id) && $_SESSION['query_data']['id']) {
			$_SESSION['query_data']['id'] = null;
		}


		$this->assign('info', $info);

		// 数据库配置的名称
        if(isset($GLOBALS['conf']['selectors']['_databases'])) {
            $db_list = array_keys($GLOBALS['conf']['selectors']['_databases']);
        } else {
            $db_list = array_keys($GLOBALS['conf']['DB_ARRAYS']);
        }
		$this->assign('db_list', $db_list);

		// 保存的url
		$action = web_url ( MODULE_NAME, 'query_exec' );
		$this->assign ( 'action', $action );


		$this->display('query_data');
	}


	public function query_exec(){
		set_time_limit(0);
		global $_REQ;
		$id = $_REQ['id'];
		$data['id'] = $id;
		$data['title'] = $_REQ['title'];
		$data['db'] = trim($_REQ['db']);
		$data['type'] = trim($_REQ['type']);
		$data['script'] = stripslashes(trim($_REQ['script']));
		$data['status'] = 1;
		/*
		if ($data['type'] == 'mysql') {
			$sql = $data['script'];
			if (strripos($sql, 'limit ') === false) {
				$sql = trim($sql, ';');
				$sql = ($sql . ' limit 10000');
			}

			$data['script'] = $sql;
		}
		*/
		if ($id) {
			// 更新
			$data['update_time'] = time();
			$data['update_user'] = $_SESSION['user_name'];
			$result = $GLOBALS['config']->update('ncf_query', $data, "id={$id}");
		} else {
			$data['create_time'] = time();
			$data['create_user'] = $_SESSION['user_name'];
			$result = $GLOBALS['config']->insert('ncf_query', $data);
			$id = $result;
		}
		$_SESSION['query_data'] = $data;
		$table_json = json_encode(array('is_table' => 1));
		$data['table_json'] = $table_json;
		$row = $this->handle_get_data($data);


		if (empty($row)) {
			$this->out('error', '查询失败');
		}

		$json['id'] = $id;
		$json['script'] = $data['script'];
		if (empty($row['list'])) {
			$json['html'] = '未查询到数据';
			$this->out('ok', $json);
		}

		$list = $row['list'];
		if (is_array($list)) {
			$table_title = array();
			foreach($list as $key=>$val){
				if (is_array($val)) {
					foreach($val as $k=>$v){
						$table_title[$k] = array( 'desc' => $k);
					}
					break;
				} else {
					$table_title[$key] = array( 'desc' => $key);
				}
			}

			T::import('libs.ORG.htmlcss');
			$json['html'] = htmlcss::tablecol ( $list, array('fds' => $table_title) );
		} else {
			$json['html'] = $list;
		}
		if ($_REQ['is_export'] == 1) {
			$this->handle_export(array(array('table_title' =>$table_title,'list' => $list)));
			exit;
		} else {
			$this->out('ok', $json);
		}

	}

	public function query_delete(){
		global $_REQ;
		$id = $_REQ['id'];
		$table = 'ncf_query';
		$id = !empty($id) ? intval($id) : 0;
		if (empty($id)) {
			exit(json_encode(array('info' => 'error', 'data' => '请选择要彻底删除的记录！')));
		}

		$result = $GLOBALS['config']->del($table, "id={$id}");

		if ($result) {
			exit(json_encode(array('info' => 'ok', 'data' => '彻底删除成功')));
		} else {
			exit(json_encode(array('info' => 'error', 'data' => '彻底删除失败')));
		}
	}


	
	public function go_to_darwin(){
		
 		//p("GO_TO_DARWIN");
		
		$darwin_url = 'http://7020.datacenter.ucf/darwin/service/';
		
		$action = '/tool/darwin_do';
		
		$this->assign('action', $action);
		//echo 1;	
		$this->assign('url', $darwin_url);
		//echo 2;
		$this->display('demo');
		echo 3;
	}

	
	public function authorization(){	
		header("Content-type: text/html; charset=utf-8");
// 		p("AUTHORIZATION");
		if ($GLOBALS['conf']['ENV'] == 'online') {
			$auth_url = "http://baize.corp.ncfgroup.com";
			//$auth_url = "http://172.31.33.48:8200";
		}else{
			$auth_url = "http://ban2.log.ncfgroup.com";
		}
		
// 		if($_SERVER['REQUEST_URI'] == '/tool/Dquery')
// 		{
// 			$menu_uri = 'api/demo';
// 		}else {
// 			//???
// 			echo 'WRONG URI';
// 			exit;
// 		}
		$menu_uri = 'tool/dquery';
		
		$param = array(
				//p($_SERVER['REQUEST_URI']);
				// to be parsed
				"menu_url"=>$menu_uri,
 				//"menu_url"=>'api/demo',
				"user_name"=>$_SESSION['user_name'],
				// "user_name"=>'wanghoubao',
				"time"=>time(),
				"sign"=>"",
				"platform"=>"darwin"
		);
		//p($param);
		$param['sign'] = md5($param['user_name'] . $param['time'] . $GLOBALS['conf']['MENU_SAPI_AUTH_DARWIN_SALT']);
		$url_param = web_url('api', "platform_user_menu");
		$auth_url.=$url_param;
		T::import('libs.function.fetch');
		//p($auth_url);
		//p($param);
		$rs = post_by_curl($auth_url,$param);
		
		//p($rs);
		
		// LOGIC
		if($rs == NULL)
		{
			echo 'Authorization Error';
			exit;
		}
		else
		{
			$result = json_decode($rs['cont'],true);
			//p($result);
			return $result;
		}
		
		// to do *****
 		$result = json_decode($rs['cont'],true);
		p($result);
 		return $result;
	}

	public function darwin_do_ori(){
// 		p("DARWIN_DO");
// 		p('wierd');
		
		$url = 'http://7020.datacenter.ucf/darwin/service/';
		$url = 'http://127.0.0.1:7020/darwin/service/';

		$param['action'] = $_POST['title'];
			
		if($_POST['title'] == 'Q')
		{
			$param['cypher'] = $_POST['script'];
		}
		if($_POST['title'] == 'V')
		{
			$param['id'] = 3;
			$param['level'] = $_POST['script'];
		}
		if($_POST['title'] == 'D')
		{
			$param['cypher'] = $_POST['script'];
		}
		
		$param['action'] = 'D';
			 
		if($_POST['input_type'] == 'ID')
		{
			$para_name = 'id';
			$para_value = $_POST['input_value'];
		}
		else if($_POST['input_type'] == 'NAME')
		{
	    	$user_name = $_POST['input_value'];
	    	
	    	// 	    	curl -H"Content-Type:application/json"   -d'{"server_id":"darwin-plugin", "action":"getUserIdByMobile", "args":["18701402694"] }'  http://api.bi.corp.ncfgroup.com/test1
	    	//	    	$faceR = get_curl_face("http://api.bi.corp.ncfgroup.com/test1", $data);
	    	$face_url = "http://api.bi.corp.ncfgroup.com/";
	    	$face_param['server_id'] = 'darwin-plugin';
	    	$face_param['action'] = 'getUserIdByUserName';
	    	// 	    	$face_param['args'][] = '18701402694';
	    	$face_param['args'][] = $user_name;
	    	$json = json_encode($face_param);
	    	
	    	$faceR = get_curl_face($face_url, $json, 'post');
	    	
	    	$face_user_name = json_decode($faceR, true);
//	    	p($face_user_name);
	    	// settings
	    	$para_name = 'id';
	    	$para_value = $face_user_name['data'];
			
			
		}
		else if($_POST['input_type'] == 'ALIAS')
		{
			$para_name = 'id';
			$para_value = HexToUserId(substr($_POST['input_value'],1));
		}
		else if($_POST['input_type'] == 'MOBILE')
		{
			$mobile = $_POST['input_value'];
		
			 
			$face_url = "http://api.bi.corp.ncfgroup.com/";
			$face_param['server_id'] = 'darwin-plugin';
			$face_param['action'] = 'getUserIdByMobile';
		
			$face_param['args'][] = $mobile;
			$json = json_encode($face_param);
		
			$faceR = get_curl_face($face_url, $json, 'post');
		
			$face_mobile = json_decode($faceR, true);
// 			echo "MOBILE";
//  			p($face_mobile);
			// settings
			$para_name = 'id';
			$para_value = $face_mobile['data'];
		}
		 
		 
		$param['d3_invest'] = 'match p = (n:Customer{' . $para_name. ':\'' . $para_value . '\'})-[r:REFER_INVEST*' .
		    					$_POST['title3'] . '..' .$_POST['title4'] .']->m return nodes(p) AS nodes, rels(p) AS rels';
		    					 
		$param['d3_register'] = 'match p = (n:Customer{' . $para_name. ':\'' . $para_value . '\'})-[r:REFER_REGISTER*' .
		    					$_POST['title3'] . '..' .$_POST['title4'] .']->m return nodes(p) AS nodes, rels(p) AS rels';

		    					 
		$result = $this->get_darwin_data($url, $param);

	
        $result = $this->get_graph_info('invest', $result);
		$result = $this->get_graph_info('register', $result);

		$this->out('ok', $result);
	}
	
	
	
	public function get_graph_info($id, $result){
		$arr = $result[$id]['nodes'];
		 
		$cnt = count($arr);
		 
		$max = 0;
		 
		$cntByLevel = array();
				 
		for ($i = 0; $i < $cnt; $i++)
		{
			$level = $arr[$i]['group'];
			if($level > $max){
				$max = $level;
			}
			$cntByLevel[$level] += 1;
		}
	 
		if($id == 'register')
		{
			$type = '推荐注册';
		}
		elseif($id == 'invest')
		{
			$type = '推荐投资';
		}
	 
		$info = $type . '关系图信息: <br/>' . '最大推荐层深: ' . $max . '<br/>' . '节点总数: ' . $cnt . '<br/>';
		for($i = 1; $i <= $max; $i++)
		{
		    $info = $info . '层级: ' . $i . ' \\ ' . '对应节点数: ' . $cntByLevel[$i] . '<br/>';
		}
		$result[$id]['info']=array('info'=>$info);
		return $result;
		    						//	    p($result);
	}
	
	
	public function get_darwin_data($url, $param, $time=0, $method='get'){
		// 密钥
		$t = time();
		$enkey = $this->make_enkey($t);
		$param['enkey'] = $enkey;
	
		// 		p($param);
	
		$result = get_curl($url, $param, 'get');
	
		$data = $this->handle_result($result);
	
	
		// 记录日志
	
		return $data;
	}
	
	
	public function handle_result($result){
		if (empty($result)) {
			return array();
		}
	//       p($result);
		$result = json_decode($result,true);
	//		p("After: " + $result);
		return $result;
	}
	/**
	 * 生成密钥
	 * @param number $time
	 */
	protected function make_enkey($time = 0){
		/**
		 *           静态key "beetle@abc123"
		 long型时间后4位 (1430303570255 / 1000) % 10000 = 0255
		 动态key = 0255 + 静态key
		 enkey = 0255 + MD5(动态key)
		 */
		$time_sub = substr($time, -4);
		//$key = $time_sub . $this->static_key;
		$key = 'darwin';
		$enkey = $time_sub.substr(md5($key), -4);
	
		return $enkey;
	}
	
	function HexToUserId($hex, $start_id = 1000000, $cardinal_number = 16777216) {
		$replace = array('I', 'O');
		$search = array('Y', 'Z');
		$result = str_replace($search, $replace, $hex);
		$ori = base_convert($result, 32, 10) - $cardinal_number;
	
		if ($ori < 0) {
			return base_convert($hex, 16, 10);
		}
	
		return $ori;
	}

	public function Dquery(){
 		//p("DQUERY");
		$curr_url = $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		// 		p($curr_url);
	
		// LOGIC FOR AUTHORIZATION CACHE
			
 		//echo 1111;


		if($_SESSION[$curr_url]['cached'] && time() - $_SESSION['last_time'] < 600){
			//echo 2222;
			$this -> go_to_darwin();
			$_SESSION['last_time'] = time();
		}else {
			//p(111);
			$auth_result = $this -> authorization();
			//p($auth_result);
			if($auth_result['status']==0 && $auth_result['desc']=='success'){
				$_SESSION[$curr_url]['cached'] = true;
				$_SESSION['first_time'] = time();
				$_SESSION['last_time'] = time();
 				//p($_SESSION);
				$this -> go_to_darwin();
			}else{
				p('AUTH_FAILED');
				//p($auth_result['desc']);
				exit;
			}
// 			$status = $auth_result['status'];
// 			switch($status)
		}
	}
	public function darwin_do()
	{
		//echo 22222;
		// GET START ID : CHECK
		$id_value = $this -> getUserId();
// 		p($id_value);
		
		// GET RESULT FROM BACKEND : CHECK
		$graph_json_map = $this -> getCypherResult($id_value);
		//echo 11111;
		// GRAPH_INFO_ADDITION : CHECK
		$graph_json_map = $this -> get_graph_info('register', $graph_json_map);
		$graph_json_map = $this -> get_graph_info('invest', $graph_json_map);
		//p($graph_json_map);	
		// TABLE_INFO_ADDITION (NEW FEATURE): 
		$graph_json_map = $this -> get_table_info('register',$graph_json_map);
		
		//p($graph_json_map);
		
		$this->out('ok', $graph_json_map);
	}
	
	
	public function getUserId()
	{
		// 1. Deal with all inputs, including X -> i by face

		// TESTS : ALL PASS
		
// 		// Test1 : PASS
// 		$_POST['input_type'] = 'ID';
// 		$_POST['input_value'] = 4;
		
// 		// Test2 : PASS
// 		$_POST['input_type'] = 'ALIAS';
// 		$_POST['input_value'] = 'FHD7B2';
		
// 		// Test3 : PASS
// 		$_POST['input_type'] = 'NAME';
// 		$_POST['input_value'] = 'wenyanlei';
		
		// Test4 : PASS
		//$_POST['input_type'] = 'MOBILE';
		//$_POST['input_value'] = '15201221131';
		
		if($_POST['input_type'] == 'ID')
		{
			$para_value = $_POST['input_value'];
		}
		else if($_POST['input_type'] == 'ALIAS')
		{
			$para_value = HexToUserId(substr($_POST['input_value'], 1));
		}
		else if($_POST['input_type'] == 'NAME' || $_POST['input_type'] == 'MOBILE')
		{
			$face_url = "http://api.bi.corp.ncfgroup.com/";
			$face_param['server_id'] = 'darwin-plugin';
			
			if($_POST['input_type'] == 'NAME')
			{
				$face_param['action'] = 'getUserIdByUserName';
			}
			else if($_POST['input_type'] == 'MOBILE')
			{
				$face_param['action'] = 'getUserIdByMobile';
			}
			$face_param['args'][] = $_POST['input_value'];
			$json = json_encode($face_param);			
			$faceR = get_curl_face($face_url, $json, 'post');
			$para_value = json_decode($faceR, true)['data'];
		}
		return $para_value;
	}
	
	public function getCypherResult($id_value)
	{
		// 2. communicate to backend (IMPORTANT)
		
		// offline
		//$url = 'http://7020.datacenter.ucf/darwin/service/';
		
		// online
		$url = 'http://127.0.0.1:7020/darwin/service/';
		
		$param['action'] = 'D';
		
		$param['d3_invest'] = 'match p = (n:Customer{id:\'' . $id_value . '\'})-[r:REFER_INVEST*' .
				$_POST['title3'] . '..' . $_POST['title4'] .']->m return nodes(p) AS nodes, rels(p) AS rels';
		
		$param['d3_register'] = 'match p = (n:Customer{id:\'' . $id_value . '\'})-[r:REFER_REGISTER*' .
				$_POST['title3'] . '..' .$_POST['title4'] .']->m return nodes(p) AS nodes, rels(p) AS rels';
		
		// 密钥
		$t = time();
		$enkey = $this->make_enkey($t);
		$param['enkey'] = $enkey;
		
		//p($url);
		//p($param);
		
		
 		$result = get_curl($url, $param, 'get');
		$data = $this->handle_result($result);
		//p($data);
		return $data;
	}
	
	public function get_table_info($type, $graph_map)
	{
// 		$arr[0]['id'] = "2573755";
// 		$arr[0]['name'] = "m13466514786";
// 		$arr[0]['group'] = 2;
		
// 		$arr[1]['id'] = "2775152";
// 		$arr[1]['name'] = "m15210824891";
// 		$arr[1]['group'] = 2;
// 		$j = json_encode($arr);
// 		p($j);
		
// 		foreach($arr as $key=>$value)
// 		{
// 			p($arr[$key]['id']);
// 		}
		
		//p($type);
		//p($graph_map);	
		$nodes = $graph_map[$type]['nodes'];
		//$arr = $nodes;		
		//echo nodes;
		//p($nodes);
		//p($arr);
 		$ids_to_face = array();
		
		foreach($nodes as $key => $value)
		{
			$ids_to_face[] = $nodes[$key]['id'];
// 			$param_to_face[] = $graph_map[$type]
		}
		//p($ids_to_face);
		
		$face_url = "http://api.bi.corp.ncfgroup.com/";
		$face_param['server_id'] = 'darwin-plugin';
		$face_param['action'] = 'getUserNameAndReferUserName';
		
		$face_param['args'][] = $ids_to_face;
		$json = json_encode($face_param);
		
		$faceR = get_curl_face($face_url, $json, 'post');
		
		$face_result = json_decode($faceR, true);
		//   		p($face_mobile['data']);
		$result_map = $face_result['data'];
		//p($result_map);
		//p('------------------------');
		foreach($nodes as $key => &$value)
		{
//  			p($key);
 			$id = $nodes[$key]['id'];
//  			p($id);
  			$value['userId'] = $result_map[$id]['userId'];
  			$value['userName'] = $result_map[$id]['userName'];
  			$value['referUserId'] = $result_map[$id]['referUserId'];
  			$value['referUserName'] = $result_map[$id]['referUserName'];
//			$value['group'] = 
		}
		$table_title = array (
				'userId' => array( 'desc' => '用户ID'),
				'userName' => array( 'desc' => '用户姓名'),
				'group' => array( 'desc' => '所处层级'),
				'referUserId' => array ( 'desc' => '推荐人ID'),
				'referUserName' => array ( 'desc' => '推荐人姓名'),
		);
		//p($arr);
		T::import('libs.ORG.htmlcss');
		$table_html = htmlcss::tablecol ( $nodes, array('fds' => $table_title) );
		
		//p($table_html);
		
		$graph_map[$type]['table'] = $table_html;
		
// 		$table = array();
// 		$table['table_html'] = $table_html;
// 		$table['list'] = $result_map;
// 		p($table);
// 		$this->out('ok', $table);
// 		$result[$id]['info']=array('info'=>$info);
// 		return $result;
		return $graph_map;
    }
}
