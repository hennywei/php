<?php

namespace Base;

abstract class Controller extends \Phf\Mvc\Controller implements BaseInterface
{
	protected $view = null;

	protected $router = null;


	/*
	 * 返回权限配置
	 */
	static public function authorities()
	{
		return [];
	}

	public static function actions()
	{
		return [];
	}

	//无需登录的板块
	protected static $allowed_controllers = [
		'public'	=>	'*'
	];

	public function initialize()
	{
		global $view;
		$this->view = $view;

		global $router;
		$this->router = $router;

		$this->checkAllowed();
		$this->checkAuth();

		if (!$this->isAjax())
		{
			$this->view->setVar('_params', [
				'controller'	=>	$this->getControllerName(),
				'action'	=>	$this->getActionName()
			]);
		}
	}

	//退出登录
	abstract function logout();

	//获取登录者过期时间
	abstract function expire();

	/**
	 * 检查需要登陆的板块 
	 * 如果需要登陆但是又没登录则跳转到登录界面
	 */
	private function checkAllowed()
	{
		if (!$this->needLogin())
			return true;

		if (!$this->isLogin())
			$this->login();

		/*
		if ($this->expire() < time())
		{
			$this->logout();
			$this->login();
		}
		 */

		return true;
	}

	/**
	 * 是否需要登录
	 */
	public function needLogin()
	{
		$needLogin = false;
		$controllerName = $this->getControllerName();
		$actionName = $this->getActionName();

		if (!array_key_exists($controllerName, self::$allowed_controllers)) {
			$needLogin = true;
		} else if ( self::$allowed_controllers[$controllerName] != '*' 
					and !in_array($actionName, self::$allowed_controllers[$controllerName]) ) {
			$needLogin = true;
		}
		
		return $needLogin;	
	}

	//登录
	function login()
	{
		$this->redirect(\Func\url('/public/login', true));
	}

	public function checkAuth()
	{
	}

	/**
	 * 单页面展示
	 */
	public function single($pagename, $params = [])
	{
		$this->noMainLayout();
		if (!empty($params))
			$this->view->setVars($params);
		$this->view->render('single', $pagename);
		exit();
	}

	/**
	 * 重定向
	 */
	public function redirect($url)
	{
		$this->response->redirect($url)->sendHeaders();
	}

	public function getControllerName()
	{
		return $this->router->getControllerName();
	}

	public function getActionName()
	{
		return $this->router->getActionName();
	}

	public function emptyAction()
	{
		if ($this->isAjax())
		{
			exit('request error .');
		}
		echo 'base Controller .';
	}

	public function isAjax()
	{
		return \Func\isAjax();
	}

	public function ajaxReturn($data, $success = true)
	{
		\Func\ajaxReturn($data, $success);
	}

	/**
	 * ajax返回错误数据
	 */
	public function error($output)
	{
		$this->ajaxReturn($output, false);
	}

	/**
	 * ajax返回成功数据
	 */
	public function success($output)
	{
		$this->ajaxReturn($output);
	}

	/**
	 * 失败单页面
	 */
	public function _404($err)
	{
		$this->single('404', $err);
	}

	/**
	 * 禁用layout
	 * @param $main: true则同时禁用main layout
	 */
	public function noLayout($main = false)
	{
		$opts = [
			\Phf\Mvc\View::LEVEL_LAYOUT	=>	true
		];
		if ($main)
			$opts[\Phf\Mvc\View::LEVEL_MAIN_LAYOUT] = true;

		$this->view->disableLevel($opts);
	}

	/**
	 * 禁用main layout
	 */
	public function noMainLayout()
	{
		$this->view->disableLevel([
			\Phf\Mvc\View::LEVEL_MAIN_LAYOUT => true
		]);
	}

	/**
	 * 错误单页面
	 */
	public function pageError($type = '')
	{
		$params = [];
		switch ($type) {
			case 'param':
				$params['msg'] = '参数错误';
				break;
			case 'permission':
				$params['msg'] = '无权限';
				break;
			default:
				$params['msg'] = '页面不存在';
				break;
		}
		if ($this->isAjax())
			$this->error($params['msg']);
		$this->single('error', $params);
	}

	/**
	 * 成功单页面
	 */
	public function pageSuccess($msg, $url, $seconds = 3)
	{
		$this->view->setLayout('single');
		$this->view->setVars([
			'msg'	=>	$msg,
			'seconds'	=>	$seconds,
			'url'	=>	$url	
		]);
		$this->view->pick('single/success');
	}

	/**
	 * 分页
	 */
	public function page($count, $limit, $p)
	{
		$page = new \Util\Page($count, $limit, $p);
		return $page->getPage();
	}

	public function limit($p = 1, $pagesize = '')
	{
		empty($p) and $p = 1;
		if (empty($pagesize))
		{
			$pagesize = $this->getConfig('pagination', 'limit');
		}
		$offset = ($p-1) * $pagesize;
		return [$pagesize, $offset];
	}

	public function captcha($width = 85, $height = 35, $color = '', $type = 'tpng')
	{
		\Util\Captcha::make($width, $height, $color, $type);
	}

	public function upload($dir = '', $maxSize = '')
	{
		$baseDir = 'upload/';

		if (empty($maxSize) || !is_integer($maxSize))
			$maxSize = 1024*1024*1; //1M

		$files = [];
		if ($this->request->hasFiles())
		{
			foreach ($this->request->getUploadedFiles() as $file)
			{
				$fileinfo = [];

				$filesize = $file->getSize();

				$dir = $dir ? ($baseDir . trim($dir, '/')) : $baseDir;
				if (!is_dir($dir))
					mkdir($dir);
				$name = date('YmdHis').'_'.mt_rand(1000,9999).'_'.$file->getName();
				$filename = trim($dir, '/') . '/' . $name;

				if ($filesize > $maxSize)
				{
					$fileinfo['errmsg'] = '尺寸过大';
					$fileinfo['success'] = 0;
				}
				else
				{
					$file->moveTo($filename);
					$fileinfo['success'] = 1;
				}

				$fileinfo['filename'] = $filename;
				$fileinfo['filesize'] = number_format($filesize / 1024, 2) . 'kb';

				$files[] = $fileinfo;
			}

			return $files;
		} 
		return [[
			'success'	=>	0,
			'errmsg'	=>	'文件不能为空'
		]];
	}

	/**
	 * 获取url参数
	 */
	public function urlParam($index = 0)
	{
		return $this->dispatcher->getParams()[$index];
	}

	public function getConfig($type, $param)
	{
		global $config;
		return $config->$type->$param;
	}

}
