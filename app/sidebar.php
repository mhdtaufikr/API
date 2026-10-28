<?php
(static function(): void {
$current=basename($_SERVER['SCRIPT_NAME']??'')?:'index.php';
$links=[
    ['MENU UTAMA',[
        ['./','Ringkasan','index.php'],
        ['attendance.php','Data Absensi','attendance.php'],
        ['talenta-attendance.php','Cek di Talenta','talenta-attendance.php'],
        ['settings.php','Pengaturan','settings.php'],
    ]],
];
?><aside class="app-sidebar"><div class="brand">MKM<span>ABSENSI KE TALENTA</span></div><nav aria-label="Menu utama"><?php foreach ($links as [$group,$items]):?><div class="navlabel"><?=e($group)?></div><div class="sidebar-group"><?php foreach ($items as [$href,$label,$page]):$active=$current===$page || ($page==='attendance.php'&&in_array($current,['technical.php','queue.php','machine-simulator.php'],true));?><a class="side-link<?=$active?' active':''?>" href="<?=e($href)?>" <?=$active?'aria-current="page"':''?>><?=e($label)?></a><?php endforeach;?></div><?php endforeach;?></nav><div class="aside-note">Company <?=e(env('MEKARI_COMPANY_ID'))?><br><?=env('INTEGRATION_STORAGE')==='legacy_app'?'Kirim semua karyawan':'Uji satu karyawan'?><br><span class="sidebar-safe"><?=enabled('DRY_RUN')?'Mode cek: belum mengirim':'Pengiriman perlu validasi'?></span></div></aside><?php })(); ?>
