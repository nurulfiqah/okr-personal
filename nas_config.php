<?php
define('CORP_NAS_IP',       '172-18-28-55.alprodc.direct.quickconnect.to');
define('CORP_NAS_PORT',     5101);
define('CORP_NAS_USER',     'DI');
define('CORP_NAS_PASSWORD', 'DI@alpro');
define('CORP_NAS_FOLDER',   '/DigitalInnovation/okr');

function corpNasConnect() {
    require_once __DIR__ . '/lib/synologynas.php';
    return new CorporateNAS(CORP_NAS_IP, CORP_NAS_PORT, CORP_NAS_USER, CORP_NAS_PASSWORD);
}
