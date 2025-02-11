<?php

class Loan extends Model
{

	public static function format($data)
	{
		static $repay_method = null;
		static $use_type = null;
		static $loan_type = null;
		static $status = null;

		!$repay_method and $repay_method = \App\Config\Loan::repayMethod();
		!$use_type and $use_type = \App\Config\Loan::useType();
		!$loan_type and $loan_type = \App\Config\Loan::loanType();
		!$status and $status = \App\Config\Loan::status();

		foreach ($data as &$row) 
		{
			$row['repay_method_text'] = $repay_method[$row['repay_method']];
			$row['use_type_text'] = $use_type[$row['use_type']];
			$row['loan_type_text'] = $loan_type[$row['loan_type']];
			$row['addtime'] = date('Y/m/d', $row['addtime']);
			$row['status_text'] = $status[$row['status']];
			$row['amount'] = str_replace('.00', '', $row['amount']);
			$row['contract_text']	=	$row['contract'] ? '已签' : ' - ';
			$row['gps_text']	=	$row['gps'] ? '已安装' : ' - ';
			$row['car_key_text']	=	$row['car_key'] ? '已拿' : ' - ';
			$row['pledge_notary_text']	=	$row['pledge_notary'] ? '是' : ' - ';
			if ($row['begintime'])
				$row['begintime'] = date('Y-m-d', $row['begintime']);
		}
		return $data;
	}

	//全国风控中心处理：批准|拒绝
	public static function deal($uid, $data)
	{
		$loanSketch = LoanSketch::findByUid($uid);
		if (!$loanSketch)
			return false;

		$loan = Loan::findFirst("uid=$uid");

		if (!$loan) {
			$loan = new Loan();
			$loan->addtime = time();
		}
		$loan->uptime = time();
		$loan->gps = 0;
		$loan->contract = 0;
		$loan->car_key = 0;
		$loan->pledge_notary = 0;
		$loan->remit_certify = 0;
		$loan->bank = '';
		$loan->bank_card = '';
		$data = array_merge($loanSketch, $data);

		$fields = ['uid', 'oid', 'amount', 'loan_type', 'deadline', 'repay_method', 'loan_type',
			'use_type', 'use_type_info', 'deadline_type', 'days', 'apr', 'repay_source', 'description',
			'reason', 'remark', 'status'];
		foreach ($data as $field=>$value)
		{
			if (in_array($field, $fields))
				$loan->$field = $value;
		}
		if ($loan->save())
			return true;

		$loan->outputErrors($loan);
		return false;
	}

	private static function columns($type = ['base'])
	{
		$base = 'Loan.lid, Loan.uid, Loan.oid, Loan.amount, Loan.deadline, Loan.deadline_type, Loan.loan_type,
			Loan.use_type, Loan.use_type_info, Loan.repay_method, Loan.repay_source, Loan.days, Loan.apr, Loan.description,
			Loan.addtime, Loan.status, Loan.reason, Loan.remark';

		$repay = 'Loan.begintime, Loan.endtime, Loan.return_num, Loan.return_amount, Loan.remain_amount, Loan.last_repay_time,
			Loan.next_repay_time';
		$other = 'Loan.bank, Loan.bank_card, Loan.contract, Loan.gps, Loan.remit_certify, Loan.car_key, Loan.pledge_notary';

		$branch = 'B.name bname';

		$user = 'U.realname, U.idcard, U.mobile';

		$columns = '';
		foreach ($type as $v)
		{
			$columns .= ', '.${$v};
		}
		return trim($columns, ', ');
	}

	private static function formatConditions($condition)
	{
		return str_replace(['{User}', '{LoanSketch}', '{Loan}', '{Branch}'], ['U', 'LoanSketch', 'Loan', 'B'], $condition);
	}

	public static function findByUid($uid)
	{
		$info = Loan::findFirst('uid='.intval($uid));
		if (!$info)
			return false;
		return self::format([$info->toArray()])[0];
	}

	public static function findByLid($lid)
	{
		$info = Loan::findFirst($lid);
		if (!$info)
			return false;
		return self::format([$info->toArray()])[0];
	}

	public static function all($condition = '', $limit = [10, 0], $columns = ['base', 'branch'])
	{
		$condition = self::formatConditions($condition);

		$query = self::query()
					->leftJoin('User', 'U.uid=Loan.uid', 'U')
					->leftJoin('Branch', 'B.bid=U.bid', 'B')
					->columns(self::columns($columns))
					->where($condition);

		$count = $query->execute()->count();
		$list = $query->limit($limit[0], $limit[1])
						->orderBy('Loan.addtime desc')
						->execute();
		$list = $list ? $list->toArray() : [];

		return [
			'list'	=>	Loan::format($list),
			'count'	=>	$count
		];
	}

	/**
	 * 按LID更新数据
	 * 合同签署、汇款凭证等
	 */
	public static function updateByUid($uid, $data)
	{
		$loan = self::findFirst("uid=$uid");
		if (!$loan)
			return false;
		foreach ($data as $field=>$value)
		{
			$loan->$field = $value;
		}
		if ($loan->update())
			return true;
		$this->outputErrors($loan);
		return false;
	}

	public static function incByUid($uid, $field, $point = 1)
	{
		$loan = self::findFirst("uid=$uid");
		if (!$loan)
			return false;
		$loan->$field = $loan->$field + $point;
		if ($loan->update())
			return true;
		return false;
	}

	/**
	 * 判断还款期数是否有效
	 */
	public static function isValidNo($uid, $no)
	{
		$loan = self::findFirst("uid=$uid");
		if (!$loan)
			return false;
		return ($no > $loan->return_num) and ($no <= $loan->deadline);
	}

	/**
	 * 更新还款数据
	 * @param $amount: 还款金额, 单位 元
	 * @param $no: 第几期
	 */
	public static function updateRepay($uid, $amount, $no, $date)
	{
		$loan = self::findFirst("uid=$uid");
		if (!$loan)
			return false;
		$loan->return_amount = $loan->return_amount + $amount;
		$loan->remain_amount = $loan->amount * 10000 - $loan->return_amount;
		$loan->return_num = $loan->return_num + 1;
		//最后一次还款时间
		$loan->last_repay_time = $date;
		//下一次应还款时间
		if ($no < $loan->deadline)
		{
			$no++;
			$next_repay_time = strtotime("+$no month", $loan->begintime);
		}
		else
		{
			$next_repay_time = 0;
		}
		$loan->next_repay_time = $next_repay_time;
		return $loan->update();
	}

	public static function updateStatus($uid, $status)
	{
		$loan = self::findFirst("uid=$uid");
		if (!$loan)
			return false;
		$loan->status = $status;
		return $loan->update();
	}

	public static function infos($uid)
	{
		$infos = User::infos($uid);
		$infos['loan'] = Loan::findByUid($uid);

		return $infos;
	}

	//反馈
	public static function advise($uid, $foid, $adviseType, $reason, $loan = false)
	{
		$isLoanSketch = false;
		switch ($adviseType) {
		case 'loansketch':
			$status = \App\LoanStatus::getStatusSketch();
			$oid = User::findFirst("uid=$uid")->oid;
			$isLoanSketch = true;
			break;
		case 'visit':
			$status = \App\LoanStatus::getStatusCarAssess();
			$oid = Visit::findFirst("uid=$uid")->oid;
			$isLoanSketch = true;
			break;
		case 'car':
			$status = \App\LoanStatus::getStatusVisit();
			$oid = Car::findFirst("uid=$uid")->oid;
			$isLoanSketch = true;
			break;
		case 'face':
			$status = \App\LoanStatus::getStatusChecked();
			$oid = Face::findFirst("uid=$uid")->oid;
			$isLoanSketch = true;
			break;
		}
	
		if (empty($oid))
			return false;

		if ($isLoanSketch) {
			$model = LoanSketch::findFirst("uid=$uid");
			$model->status = $status;
			$model->update();
		}
		Advise::add($uid, $oid, $foid, $adviseType, $reason);
		return true;
	}
}
