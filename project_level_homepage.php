<?php

# namespace Stanford\LogViewerModule;

require_once \ExternalModules\ExternalModules::getProjectHeaderPath();

$em = new \Stanford\MirrorMasterDataModule\MirrorMasterDataModule;


?>


<h1>This is the <?= $em->getModuleName() ?> project homepage!</h1>


<?php

print "<pre>" . print_r($settings,true) . "</pre>";

require_once \ExternalModules\ExternalModules::getProjectFooterPath();