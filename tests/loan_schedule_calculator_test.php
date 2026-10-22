<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../libs/services/LoanScheduleCalculator.php';
$calculator=new LoanScheduleCalculator();
function scheduleInput(string $method): array {
    $input=['principal'=>'1000.00','method'=>$method,'installment_count'=>3,'rounding_mode'=>'half_up','residual_treatment'=>'adjust_final_installment','fees'=>['handling'=>'none']];
    if(!in_array($method,['interest_free','manual_schedule'],true)) $input+=['rate_basis'=>'per_installment','rate_per_installment'=>'0.01'];
    return $input;
}
function expectScheduleFailure(array $input): void {
    try{(new LoanScheduleCalculator())->calculate($input);}catch(InvalidArgumentException|OverflowException$error){return;}
    throw new RuntimeException('Invalid configuration was accepted');
}
function assertConservedSchedule(array $output): void {
    $principal=0;$interest=0;$repayments=0;$previous=moneyMinorUnits($output['principal']);
    foreach($output['installments']as$row){
        assertSameValue($previous,moneyMinorUnits($row['opening_principal']));
        $p=moneyMinorUnits($row['principal']);$i=moneyMinorUnits($row['interest']);$due=moneyMinorUnits($row['repayment']);
        assertSameValue($p+$i,$due);
        if($due<=0||$p<0||$i<0)throw new RuntimeException('Nonpositive repayment or negative allocation');
        $previous-=$p;assertSameValue($previous,moneyMinorUnits($row['closing_principal']));
        $principal+=$p;$interest+=$i;$repayments+=$due;
    }
    assertSameValue(0,$previous);assertSameValue(moneyMinorUnits($output['principal']),$principal);
    assertSameValue(moneyMinorUnits($output['total_interest']),$interest);
    assertSameValue(moneyMinorUnits($output['total_repayable']),$repayments);
}
test('interest-free worked example adjusts the final principal kobo',function()use($calculator){
    $out=$calculator->calculate(scheduleInput('interest_free'));assertConservedSchedule($out);
    assertSameValue(['333.33','333.33','333.34'],array_column($out['installments'],'principal'));
    assertSameValue('0.00',$out['total_interest']);assertSameValue('1000.00',$out['total_repayable']);
});
test('flat per-installment interest worked example',function()use($calculator){
    $out=$calculator->calculate(scheduleInput('flat'));assertConservedSchedule($out);
    assertSameValue(['10.00','10.00','10.00'],array_column($out['installments'],'interest'));
    assertSameValue(['343.33','343.33','343.34'],array_column($out['installments'],'repayment'));
    assertSameValue('30.00',$out['total_interest']);
});
test('independent Decimal annuity oracle: 10000 principal, 1% per installment, 12 installments',function()use($calculator){
    // Oracle independently evaluated with Python decimal precision50, ROUND_HALF_UP.
    $input=scheduleInput('reducing_equal_installments');$input['principal']='10000.00';$input['installment_count']=12;
    $out=$calculator->calculate($input);assertConservedSchedule($out);
    assertSameValue(['100.00','92.12','84.15','76.11','67.98','59.78','51.49','43.12','34.67','26.13','17.51','8.80'],array_column($out['installments'],'interest'));
    assertSameValue(array_merge(array_fill(0,11,'888.49'),['888.47']),array_column($out['installments'],'repayment'));
    assertSameValue('661.86',$out['total_interest']);assertSameValue('10661.86',$out['total_repayable']);
});
test('equal principal charges decline on the outstanding balance',function()use($calculator){
    $out=$calculator->calculate(scheduleInput('reducing_equal_principal'));assertConservedSchedule($out);
    assertSameValue(['10.00','6.67','3.33'],array_column($out['installments'],'interest'));
    assertSameValue(['343.33','340.00','336.67'],array_column($out['installments'],'repayment'));
    assertSameValue('20.00',$out['total_interest']);
});
test('interest-only worked example retains principal until final balloon',function()use($calculator){
    $out=$calculator->calculate(scheduleInput('interest_only_balloon'));assertConservedSchedule($out);
    assertSameValue(['0.00','0.00','1000.00'],array_column($out['installments'],'principal'));
    assertSameValue(['10.00','10.00','1010.00'],array_column($out['installments'],'repayment'));
});
test('manual schedule reconciles explicitly reviewed interest without authorizing its review',function()use($calculator){
    $input=scheduleInput('manual_schedule');$input['schedule']=[['principal'=>'250.00','interest'=>'5.00'],['principal'=>'250.00','interest'=>'4.00'],['principal'=>'500.00','interest'=>'1.00']];$input['reviewed_total_interest']='10.00';
    $out=$calculator->calculate($input);assertConservedSchedule($out);assertSameValue('1010.00',$out['total_repayable']);
    $input['reviewed_total_interest']='9.99';expectScheduleFailure($input);
    $input['reviewed_total_interest']='10.00';$input['schedule'][2]['principal']='499.99';expectScheduleFailure($input);
});
foreach(['half_up'=>'0.03','half_even'=>'0.02','down'=>'0.02','up'=>'0.03']as$mode=>$expected){
    test('explicit '.$mode.' rounds a 2.5-kobo interest tie correctly',function()use($calculator,$mode,$expected){
        $input=scheduleInput('flat');$input['principal']='1.00';$input['installment_count']=1;$input['rate_per_installment']='0.025';$input['rounding_mode']=$mode;
        $out=$calculator->calculate($input);assertConservedSchedule($out);assertSameValue($expected,$out['total_interest']);
    });
}
test('half-even rounds an odd 3.5-kobo tie upward',function()use($calculator){
    $input=scheduleInput('flat');$input['principal']='1.00';$input['installment_count']=1;$input['rate_per_installment']='0.035';$input['rounding_mode']='half_even';
    assertSameValue('0.04',$calculator->calculate($input)['total_interest']);
});
test('explicit final residual conserves flat-interest charge rounding',function()use($calculator){
    $input=scheduleInput('flat');$input['principal']='1.00';$input['installment_count']=2;$input['rate_per_installment']='0.025';
    $out=$calculator->calculate($input);assertConservedSchedule($out);
    assertSameValue(['0.03','0.02'],array_column($out['installments'],'interest'));assertSameValue('0.05',$out['total_interest']);
});
test('tiny principal is conserved or explicitly rejected when selected rounding is infeasible',function()use($calculator){
    $input=scheduleInput('interest_free');$input['principal']='0.03';$input['installment_count']=2;
    $out=$calculator->calculate($input);assertConservedSchedule($out);assertSameValue(['0.02','0.01'],array_column($out['installments'],'principal'));
    $input['principal']='0.01';expectScheduleFailure($input);
    $input['principal']='0.03';$input['installment_count']=4;$input['rounding_mode']='down';expectScheduleFailure($input);
});
test('zero rate is supported explicitly for declining-balance methods',function()use($calculator){
    foreach(['reducing_equal_installments','reducing_equal_principal','flat']as$method){$input=scheduleInput($method);$input['rate_per_installment']='0';$out=$calculator->calculate($input);assertConservedSchedule($out);assertSameValue('0.00',$out['total_interest']);}
    $input=scheduleInput('interest_only_balloon');$input['rate_per_installment']='0';expectScheduleFailure($input);
});
test('no financial input or policy is defaulted',function(){
    foreach(['principal','method','installment_count','rounding_mode','residual_treatment','fees']as$key){$input=scheduleInput('flat');unset($input[$key]);expectScheduleFailure($input);}
    foreach(['rate_basis','rate_per_installment']as$key){$input=scheduleInput('flat');unset($input[$key]);expectScheduleFailure($input);}
});
test('unsupported rate conversion, fees, policy fields and float money fail closed',function(){
    $base=scheduleInput('flat');
    foreach([['rate_basis','annual'],['fees',['handling'=>'financed','amount'=>'10.00']],['fees',['handling'=>'none','amount'=>'10.00']],['rate_per_installment',0.01],['principal',1000.0],['principal','-0.01'],['principal','0.001'],['rounding_mode','bank_default'],['residual_treatment','ignore'],['installment_count',0],['installment_count',1201],['installment_count',3.0],['method','unknown'],['unexpected_fee','1.00']]as[$key,$value]){$input=$base;$input[$key]=$value;expectScheduleFailure($input);}
});
test('near-zero explicit rate and maximal principal remain exact without float conversion',function()use($calculator){
    $input=scheduleInput('reducing_equal_installments');$input['principal']='9999999999999.99';$input['rate_per_installment']='0.000000000000000001';
    $out=$calculator->calculate($input);assertConservedSchedule($out);assertSameValue('9999999999999.99',$out['total_repayable']);
});
test('overflow and rounded negative amortization are rejected',function(){
    $input=scheduleInput('flat');$input['principal']='9999999999999.99';$input['rate_per_installment']='999999';expectScheduleFailure($input);
    $input=scheduleInput('reducing_equal_installments');$input['principal']='0.01';$input['rate_per_installment']='1';$input['installment_count']=3;$input['rounding_mode']='down';expectScheduleFailure($input);
});
finishTests();
