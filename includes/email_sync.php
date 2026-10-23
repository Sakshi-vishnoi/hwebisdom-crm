<?php

function inbox_config(): array { return require __DIR__.'/../config/inbox.php'; }
function inbox_is_configured(): bool { $c=inbox_config();return !empty($c['enabled']) && !empty($c['host']) && !empty($c['username']) && !str_starts_with((string)$c['username'],'YOUR_') && !empty($c['password']) && $c['password']!=='YOUR_IMAP_PASSWORD'; }
function imap_mailbox_string(array $c): string { $flags=($c['encryption']??'ssl')==='ssl'?'/imap/ssl':'/imap'; if(($c['encryption']??'')==='tls')$flags.='/tls';$flags.='/novalidate-cert';return '{'.$c['host'].':'.$c['port'].$flags.'}'.$c['folder']; }
function email_body_text($imap,$num): string { $structure=imap_fetchstructure($imap,$num);if(!$structure)return trim((string)imap_body($imap,$num));$plain='';$walk=function($parts,$prefix='')use($imap,$num,&$walk,&$plain){foreach($parts as $i=>$part){$partNo=$prefix.($i+1);if(($part->type??-1)===0){$data=imap_fetchbody($imap,$num,$partNo);$encoding=$part->encoding??0;if($encoding===3)$data=base64_decode($data);elseif($encoding===4)$data=quoted_printable_decode($data);$charset='UTF-8';foreach($part->parameters??[] as $p){if(strtolower($p->attribute??'')==='charset')$charset=$p->value;}if(strtoupper($charset)!=='UTF-8'){$converted=@iconv($charset,'UTF-8//IGNORE',$data);if($converted!==false)$data=$converted;}$plain.=$data."\n";}if(!empty($part->parts))$walk($part->parts,$partNo.'.');}};if(!empty($structure->parts))$walk($structure->parts);else{$plain=imap_body($imap,$num);if(($structure->encoding??0)===3)$plain=base64_decode($plain);elseif(($structure->encoding??0)===4)$plain=quoted_printable_decode($plain);}return trim($plain);}
function email_body_html($imap,$num): string { $structure=imap_fetchstructure($imap,$num);if(!$structure)return ''; $html='';$walk=function($parts,$prefix='')use($imap,$num,&$walk,&$html){foreach($parts as $i=>$part){$partNo=$prefix.($i+1);if(($part->type??-1)===0){$sub='';foreach($part->parameters??[] as $p){if(strtolower($p->attribute??'')==='charset')$sub=$p->value;}if(strtolower((string)($part->subtype??''))==='html'){$data=imap_fetchbody($imap,$num,$partNo);if(($part->encoding??0)===3)$data=base64_decode($data);elseif(($part->encoding??0)===4)$data=quoted_printable_decode($data);$html=$data;}}if(!empty($part->parts))$walk($part->parts,$partNo.'.');}};if(!empty($structure->parts))$walk($structure->parts);return trim($html);}
function sync_inbox(PDO $pdo): array {
 $c=inbox_config();if(empty($c['enabled']))return[false,0,'Inbound email sync is disabled.'];if(!function_exists('imap_open'))return[false,0,'PHP IMAP extension is not enabled.'];
 $imap=@imap_open(imap_mailbox_string($c),$c['username'],$c['password']);if(!$imap)return[false,0,'IMAP connection failed: '.(imap_last_error()?:'unknown error')];
 $since=date('d-M-Y',strtotime('-'.max(1,(int)$c['since_days']).' days'));$nums=imap_search($imap,'SINCE "'.$since.'"')?:[];$imported=0;$skipped=0;
 foreach($nums as $num){
  $uid=(string)imap_uid($imap,$num);if(!$uid)continue;$unique=$c['host'].':'.$c['folder'].':'.$uid;$exists=$pdo->prepare('SELECT id FROM email_messages WHERE message_uid=?');$exists->execute([$unique]);if($exists->fetchColumn()){ $skipped++;continue; }
  $header=imap_headerinfo($imap,$num);$from=$header->from[0]??null;$fromEmail=$from?($from->mailbox.'@'.$from->host):'';$fromName=$from?(($from->personal??'')):'';$subject=isset($header->subject)?imap_utf8($header->subject):'';$messageId=(string)($header->message_id??'');$dateRaw=(string)($header->date??'');$received=$dateRaw?date('Y-m-d H:i:s',strtotime($dateRaw)):date('Y-m-d H:i:s');
  if(!$fromEmail||!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)){$skipped++;continue;}
  $s=$pdo->prepare('SELECT id FROM leads WHERE LOWER(email)=LOWER(?) ORDER BY id DESC LIMIT 1');$s->execute([$fromEmail]);$leadId=(int)$s->fetchColumn();
  if(!$leadId){$skipped++;continue;}
  $text=email_body_text($imap,$num);$html=email_body_html($imap,$num);$thread=trim((string)($header->references??''));
  $ins=$pdo->prepare('INSERT INTO email_messages(lead_id,direction,message_uid,message_id,thread_key,from_email,from_name,to_email,cc_email,subject,body_text,body_html,received_at,raw_headers) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $to=[];foreach($header->to??[] as $x)$to[]=$x->mailbox.'@'.$x->host;$cc=[];foreach($header->cc??[] as $x)$cc[]=$x->mailbox.'@'.$x->host;
  $raw=imap_fetchheader($imap,$num);$ins->execute([$leadId,'inbound',$unique,$messageId,$thread,$fromEmail,$fromName,implode(', ',$to),implode(', ',$cc),$subject,$text,$html,$received,$raw]);
  log_activity($pdo,$leadId,null,'Email Received','Email received from '.$fromEmail.': '.($subject?:'(no subject)'));$imported++;
 }
 imap_close($imap);return[true,$imported,'Email sync complete. Imported '.$imported.' new email(s).'];
}
