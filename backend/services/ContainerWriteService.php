<?php
require_once __DIR__.'/OrderCountryService.php';
require_once __DIR__.'/ContainerCapacityService.php';

final class ContainerWriteService
{
    /** Decode audit metadata in PHP: MySQL 5.5 has no JSON_EXTRACT. Caller holds the request lock. */
    public static function findCreateRequest(PDO $pdo, string $key): ?array
    {
        $stmt = $pdo->prepare("SELECT entity_id,new_value,user_id FROM audit_log WHERE entity_type='container' AND action='create' AND new_value LIKE ? ORDER BY id");
        $stmt->execute(['%' . strtr($key, ['\\'=>'\\\\', '%'=>'\\%', '_'=>'\\_']) . '%']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $audit = json_decode((string) $row['new_value'], true);
            if (is_array($audit) && ($audit['idempotency_key'] ?? null) === $key) return $row;
        }
        return null;
    }

    private const TEXT = ['code'=>50,'notes'=>65535,'vessel_name'=>100,'destination_country'=>100,'destination'=>255];
    private const DATES = ['expected_ship_date','eta_date','actual_departure_date','actual_arrival_date'];

    public static function revision(array $row): string
    {
        $fields=['id','code','status','max_cbm','max_weight',...array_keys(self::TEXT),...self::DATES];
        $values=[];foreach($fields as $field)$values[$field]=(string)($row[$field]??'');
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }

    public static function normalize(PDO $pdo,array $input,array $existing=[]): array
    {
        foreach(self::TEXT as $field=>$max){
            if(!array_key_exists($field,$input))continue;
            if($input[$field]!==null&&!is_string($input[$field]))jsonError("$field must be text",422);
            $value=trim($input[$field]??'');if(mb_strlen($value)>$max)jsonError("$field exceeds its supported length",422);
            if($field==='code'){if($value==='')jsonError('Container code is required',422);$value=mb_strtoupper($value);}
            $input[$field]=$value!==''?$value:null;
        }
        foreach(self::DATES as $field){
            if(!array_key_exists($field,$input))continue;$value=$input[$field];
            if($value===null||$value===''){$input[$field]=null;continue;}
            if(!is_string($value)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))jsonError("$field must be a valid YYYY-MM-DD date",422);
        }
        $candidate=array_replace($existing,$input);
        foreach([['expected_ship_date','eta_date'],['actual_departure_date','actual_arrival_date']] as [$from,$to]){
            if(!empty($candidate[$from])&&!empty($candidate[$to])&&$candidate[$to]<$candidate[$from])jsonError("$to cannot precede $from",422);
        }
        if(array_key_exists('destination_country',$input)&&$input['destination_country']!==null){
            $countryId=OrderCountryService::resolveContainerDestinationCountryId($pdo,['destination_country'=>$input['destination_country']]);
            if(!$countryId)jsonError('Select a recognized destination country',422);
            $s=$pdo->prepare('SELECT name FROM countries WHERE id=?');$s->execute([$countryId]);$input['destination_country']=$s->fetchColumn();
        }
        return $input;
    }

    public static function assertDestination(PDO $pdo,array $existing,array $input): void
    {
        if(!array_key_exists('destination_country',$input)&&!array_key_exists('destination',$input))return;
        $candidate=array_replace($existing,$input);
        if(($candidate['destination_country']??null)===($existing['destination_country']??null)&&($candidate['destination']??null)===($existing['destination']??null))return;
        $s=$pdo->prepare('SELECT o.id,o.destination_country_id FROM shipment_drafts sd JOIN shipment_draft_orders sdo ON sdo.shipment_draft_id=sd.id JOIN orders o ON o.id=sdo.order_id WHERE sd.deleted_at IS NULL AND sd.container_id=? FOR UPDATE');$s->execute([$existing['id']]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $order){
            if(!OrderCountryService::resolveContainerDestinationCountryId($pdo,$candidate)||!OrderCountryService::orderMatchesContainer($pdo,$order,$candidate))throw new ShipmentAssignmentException('Destination must match every assigned order; move the cargo before changing country');
        }
    }
}
