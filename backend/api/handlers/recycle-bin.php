<?php
require_once __DIR__.'/../helpers.php';
require_once dirname(__DIR__,2).'/services/RecycleBinService.php';
return function(string $method,?string $id,?string $action,array $input):void {
    $pdo=getDb();
    if (!getAuthUserId()) jsonError('Unauthorized',401);
    if (!hasAnyRole(['SuperAdmin','ChinaAdmin','LebanonAdmin'])) jsonError('Recovery requires an administrator',403);
    if (!hasPageAccess('recycle_bin')) jsonError('Recycle Bin access required',403);
    try {
        if ($method==='GET' && $id===null) {
            $types=[];
            if (hasPermission('page:procurement_drafts')) $types[]='procurement_draft';
            if (hasPermission('shipment-drafts.read')) $types[]='shipment_draft';
            $type=$_GET['type']??'';$q=$_GET['q']??'';
            if (!is_string($type)||!is_string($q)||mb_strlen($q)>150) throw new DomainException('Invalid recycle-bin filter',422);
            if ($type!=='') {RecycleBinService::authorize($type);$types=[$type];}
            if (!$types) jsonError('Forbidden',403);
            $parts=[];
            foreach ($types as $kind) {
                $table=RecycleBinService::table($kind);
                $ref=$kind==='procurement_draft'?"CONCAT('PD-',r.id,' — ',r.name)":"CONCAT('Shipment draft #',r.id,COALESCE(CONCAT(' — ',r.booking_number),''))";
                $parts[]="SELECT '$kind' record_type,r.id,$ref reference,r.status original_status,r.deleted_at,r.deleted_by,r.delete_reason,u.full_name deleted_by_name FROM $table r LEFT JOIN users u ON u.id=r.deleted_by WHERE r.deleted_at IS NOT NULL";
            }
            $where=['1=1'];$params=[];
            if ($q!=='') {$where[]='(reference LIKE ? OR delete_reason LIKE ?)';$like=clmsSearchLike($q);array_push($params,$like,$like);}
            foreach (['from'=>'>=','to'=>'<'] as $field=>$op) {
                $v=$_GET[$field]??'';
                if ($v==='')continue;
                if (!is_string($v)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$v,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new DomainException('Use valid deletion dates',422);
                $where[]="deleted_at $op ?";$params[]=$field==='to'?date('Y-m-d',strtotime($v.' +1 day')):$v;
            }
            $actor=$_GET['deleted_by']??'';
            if($actor!==''){if(!is_string($actor)||!ctype_digit($actor)||(int)$actor<1)throw new DomainException('Invalid deleted-user ID',422);$where[]='deleted_by=?';$params[]=(int)$actor;}
            $limit=clmsQueryLimit($_GET['limit']??null,25,100);$offset=clmsQueryOffset($_GET['offset']??null);
            $sql='FROM ('.implode(' UNION ALL ',$parts).') rb WHERE '.implode(' AND ',$where);
            $actors=$pdo->query('SELECT DISTINCT deleted_by id,deleted_by_name name FROM ('.implode(' UNION ALL ',$parts).') actors WHERE deleted_by IS NOT NULL ORDER BY deleted_by_name,deleted_by LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
            $s=$pdo->prepare('SELECT COUNT(*) '.$sql);$s->execute($params);$total=(int)$s->fetchColumn();
            $s=$pdo->prepare('SELECT * '.$sql." ORDER BY deleted_at DESC,record_type,id DESC LIMIT $limit OFFSET $offset");$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
            // Resolve revision snapshots by type, not one query per displayed row.
            $snapshots=[];
            foreach($types as $kind){
                $ids=array_column(array_filter($rows,static fn($r)=>$r['record_type']===$kind),'id');if(!$ids)continue;
                $table=RecycleBinService::table($kind);$ph=implode(',',array_fill(0,count($ids),'?'));
                $s=$pdo->prepare("SELECT * FROM $table WHERE deleted_at IS NOT NULL AND id IN ($ph)");$s->execute($ids);
                foreach($s->fetchAll(PDO::FETCH_ASSOC) as $snapshot)$snapshots[$kind][(int)$snapshot['id']]=$snapshot;
            }
            $visible=[];
            foreach ($rows as $r) {
                $row=$snapshots[$r['record_type']][(int)$r['id']]??null;if(!$row)continue;
                $r['version']=RecycleBinService::version($row);
                $r['can_restore']=hasPermission('recycle-bin.restore',['SuperAdmin']) && hasPermission($r['record_type']==='shipment_draft'?'shipment-drafts.write':'page:procurement_drafts');
                $created=$row['created_at']??null;
                $r['can_purge']=hasAnyRole(['SuperAdmin']) && is_string($created) && $created>'0000-00-00' && $created<date('Y-m-d H:i:s',strtotime('-10 years'));
                $visible[]=$r;
            }
            jsonResponse(['data'=>$visible,'meta'=>['total'=>$total,'limit'=>$limit,'offset'=>$offset,'deleted_users'=>$actors]]);
        }
        if ($method==='POST' && ctype_digit($id??'') && in_array($action,['restore','purge'],true)) {
            if (!is_string($input['type']??null)||!is_string($input['version']??null)) throw new DomainException('Record type and revision required',422);
            if ($action==='restore') RecycleBinService::restore($pdo,$input['type'],(int)$id,(int)getAuthUserId(),$input['version']);
            else RecycleBinService::purge($pdo,$input['type'],(int)$id,(int)getAuthUserId(),$input['version'],is_string($input['confirmation']??null)?$input['confirmation']:'');
            jsonResponse(['data'=>['success'=>true]]);
        }
        jsonError('Method not allowed',405);
    } catch(DomainException $e) {jsonError($e->getMessage(),$e->getCode()?:409);}
};
