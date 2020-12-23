<?php
/**
 * Created by PhpStorm.
 * User: hjz
 * Date: 2020/10/21
 * Time: 10:24
 */

namespace app\model\logic;


use think\facade\Db;

class Arrears
{
    static $status_config = [
        '9' => '待还款',
        '1' => '待确认',
        '2' => '已确认',
        '3' => '确认失败',
    ];

    //新增债务
    public static function create( $param)
    {
        $amount = $param['zhaiquan_org_id'] ?? 0;
        if(!empty($amount)) {
            $id = Db::name('arrears')->insertGetId([
                'order_no' =>  $param['order_no'] ?? '',
                'zhaiwu_org_id' => intval( $param['zhaiwu_org_id'] ?? 0),
                'zhaiquan_org_id' => intval( $param['zhaiquan_org_id'] ?? 0),
                'amount' => intval( $param['amount'] ?? 0),
                'title' =>  $param['title'] ?? '',
                'product_no' =>  $param['product_no'] ?? '',
                'product_name' =>  $param['product_name'] ?? '',
                'user_name' =>  $param['user_name'] ?? '',
                'user_card' =>  $param['user_card'] ?? '',
                'type' =>  $param['type'] ?? 1,
                'adjust_id' =>  $param['adjust_id'] ?? 0,
            ]);

            return success(['id' => $id]);
        }
    }

    //债务/债权列表
    public static function list( $param = [])
    {
        $type =  $param['type'] ?? '';

        if($type == 'zhaiwu') {
            $type2 = 'zhaiquan';
        }elseif($type == 'zhaiquan') {
            $type2 = 'zhaiwu';
        }else{
            return error('类型有误');
        }

        $org_id = $param['org_id'] ?? 0;
        if(empty($org_id)) return error('组织有误');

        $settle_id = $param['settle_id'] ?? 0;

        $order_date_start =  $param['order_date_start'] ?? '';//下单开始时间
        $order_date_end =  $param['order_date_end'] ?? '';

        $start_date =  $param['start_date'] ?? '';//确认欠款开始时间
        $end_date =  $param['end_date'] ?? '';

        $status =  $param['status'] ?? 0;//1未还款 2已还款

        $object_org_id =  $param['object_org_id'] ?? 0;//对象组织id


        $where = [];

        if(!empty($settle_id)) $where[] = ['arrears_settle_id','=',$settle_id];

        $where[] = [$type.'_org_id','=',$org_id];

        if(!empty($order_date_start)) $where[] = ['order_date','>=',$order_date_start];
        if(!empty($order_date_end)) $where[] = ['order_date','<=',$order_date_end];

        if(!empty($start_date)) $where[] = ['create_time','>=',$start_date];
        if(!empty($end_date)) $where[] = ['create_time','<=',$end_date];

        if(!empty($status)) {
            $where[] = ['status','=',$status];
        }

        if(!empty($object_org_id)) $where[] = [$type2.'_org_id','=',$object_org_id];

        $where[] = ['amount','<>',0];

        $data = Db::name('arrears')
            ->where($where)
            ->order('id','desc')
            ->paginate(Page::config())
            ->toArray();

        if(!empty($data['data'])) {
            $org_ids = array_column($data['data'],$type2.'_org_id');
            $orgs = Db::name('org')
                ->where('id','in',$org_ids)
                ->column('name,taxpayer_num','id');

            foreach ($data['data'] as &$v) {
                $org = $orgs[$v[$type2.'_org_id']];
                $v[$type2.'_org_name'] = $org['name'] ?? '';
                $v[$type2.'_org_taxpayer_num'] = $org['taxpayer_num'] ?? '';

                $v['amount'] = get_real_price($v['amount']);

                $v['status_name'] = self::$status_config[$v['status']];
            }
        }

        return success($data);
    }

    //结算债务
    public static function settle($param)
    {
        $org_id  = $param['org_id'] ?? 0;
        $ids     = $param['ids'] ?? [];
        $remark  = $param['remark'] ?? '';
        $total_amount = intval($param['total_amount'] ?? 0);

        $trade_no     = $param['trade_no'] ?? '';//银行流水
        $account_name = $param['account_name'] ?? '';//付款方名称
        $account_bank_name = $param['account_bank_name'] ?? '';//付款方银行
        $account_no = $param['account_no'] ?? '';//付款方账号
        $to_account_name = $param['to_account_name'] ?? '';//收款方名称
        $to_account_bank_name = $param['to_account_bank_name'] ?? '';//收款方银行
        $to_account_no = $param['to_account_no'] ?? '';//收款方银行账号

        if(empty($trade_no)) return error('请输入银行流水');
        if(empty($org_id)) return error('组织有误');
        if(empty($ids))    return error('请选择订单');
        if(empty($remark)) return error('请输入备注');
        if(empty($total_amount)) return error('金额未填');

        $lists = Db::name('arrears')
            ->where('id','in',$ids)
            ->where('zhaiwu_org_id',$org_id)
            ->select();

        if(count($ids) !== count($lists)) return error('数据有误');

        $check_amount = 0;
        foreach ($lists as $v) {
            if(!empty($v['arrears_settle_id'])) return error('订单'.$v['order_no'].'已结算，请勿重复结算');
            $check_amount += $v['amount'];

            if(!isset($zhaiquan_org_id)) {
                $zhaiquan_org_id = $v['zhaiquan_org_id'];
            }else{
                if($zhaiquan_org_id != $v['zhaiquan_org_id']) return error('合并还款只能选择相同应付对象');
            }
        }
        if($total_amount != $check_amount) return error('金额不一致');

        Db::startTrans();
        try
        {
            $ids_str = implode(',',$ids);
            $ids_str = ','.$ids_str.',';

            $arrears_settle_id = Db::name('arrears_settle')->insertGetId([
                'arrear_ids' => $ids_str,
                'zhaiwu_org_id' => $org_id,
                'zhaiquan_org_id' => $zhaiquan_org_id,
                'total_amount' => $total_amount,
                'remark' => $remark,
                'trade_no' => $trade_no,
                'account_name' => $account_name,
                'account_bank_name' => $account_bank_name,
                'account_no' => $account_no,
                'to_account_name' => $to_account_name,
                'to_account_bank_name' => $to_account_bank_name,
                'to_account_no' => $to_account_no,
            ]);

            Db::name('arrears')
                ->where('id','in',$ids)
                ->update([
                    'arrears_settle_id'=>$arrears_settle_id
                ]);

            Db::commit();
        }catch (\Exception $e) {
            Db::rollback();
            return error($e->getMessage());
        }

        return success([
            'settle_id' => $arrears_settle_id
        ]);
    }

    /**
     * 结算列表
     */
    public static function settleList($param)
    {
        $type   =  $param['type'] ?? '';
        $status =  $param['status'] ?? 0;//1待确认 2已确认 3确认失败
        $object_org_id =  $param['object_org_id'] ?? 0;//对象组织id

        if($type == 'zhaiwu') {
            $type2 = 'zhaiquan';
        }elseif($type == 'zhaiquan') {
            $type2 = 'zhaiwu';
        }else{
            return error('类型有误');
        }

        $org_id = $param['org_id'] ?? 0;
        if(empty($org_id)) return error('组织有误');

        $where = [];
        $where[] = [$type.'_org_id','=',$org_id];
        if(!empty($object_org_id)) $where[] = [$type2.'_org_id','=',$object_org_id];
        if(!empty($status)) $where[] = ['status','=',$status];
        
        $data = Db::name('arrears_settle')
            ->where($where)
            ->paginate(Page::config())
            ->toArray();

        if(!empty($data['data'])) {
            $org_ids = array_column($data['data'],$type2.'_org_id');
            $orgs = Db::name('org')
                ->where('id','in',$org_ids)
                ->column('name,taxpayer_num','id');

            foreach ($data['data'] as &$v) {
                $org = $orgs[$v[$type2.'_org_id']];
                $v[$type2.'_org_name'] = $org['name'] ?? '';
                $v[$type2.'_org_taxpayer_num'] = $org['taxpayer_num'] ?? '';

                $v['total_amount'] = get_real_price($v['total_amount']);
            }
        }

        return success($data);
    }

    /**
     * 确认结算
     * @param $param
     */
    public static function confirmSettle($param)
    {
        $settle_id = $param['settle_id'];
        $org_id = $param['org_id'] ?? 0;
        $status = $param['status'] ?? 0;
        if(empty($org_id)) return error('组织有误');
        if(!in_array($status,[2,3])) return error('确认值有误');

        $where = [
            ['id','=',$settle_id],
            ['zhaiquan_org_id','=',$org_id],
        ];

        $settle_info = Db::name('arrears_settle')
            ->where($where)
            ->find();

        if(empty($settle_info)) return error('结算记录不存在'.$org_id);
        if(!in_array($settle_info['status'],[1,3])) {
            return error('该记录已处理，请刷新查看');
        }

        $arrear_ids = trim($settle_info['arrear_ids'],',');
        $arrear_ids = explode(',',$arrear_ids);

        $arrears = Db::name('arrears')
            ->where('id','in',$arrear_ids)
            ->select();


        Db::startTrans();
        try
        {
            Db::name('arrears_settle')
                ->where('id',$settle_id)
                ->update([
                    'status' => $status,
                ]);

            if($status == 1) {//同意
                //金额问题
                $total_amount = $settle_info['total_amount'];

                foreach ($arrears as $v) {
                    $remain_amount = $v['amount'] - $v['repayment'];//剩余欠款
                    if($total_amount < $remain_amount) {//剩余欠款大于剩下的total_amount
                        Db::name('arrears')
                            ->where('id',$v['id'])
                            ->inc('repayment',$total_amount)
                            ->update();
                    }else{//剩余欠款小于剩下的total_amount
                        Db::name('arrears')
                            ->where('id',$v['id'])
                            ->inc('repayment',$remain_amount)
                            ->exp('status',2)
                            ->update();
                    }
                    $total_amount -= $remain_amount;
                }

            }else{//不同意
                Db::name('arrears')
                    ->where('id','in',$arrear_ids)
                    ->update([
                        'status' => 9
                    ]);
            }

            Db::commit();
            return success();
        }catch (\Exception $e) {
            Db::rollback();
            return error($e->getMessage());
        }
    }


    //待还款条数
    public static function arrearsPendingNum($param)
    {
        $org_id = $param['org_id'] ?? 0;
        if(empty($org_id)) {
            return error('未选择');
        }

        $count = Db::name('arrears')
            ->where('zhaiwu_org_id',$org_id)
            ->where('status','in',[1,3])
            ->count();

        return success([
            'num' => $count
        ]);
    }



}