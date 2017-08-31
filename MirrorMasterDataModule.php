<?php
namespace Stanford\MirrorMasterDataModule;

class MirrorMasterDataModule extends \ExternalModules\AbstractExternalModule
{

    // Reposition the control center link to be adjacent to the mysql status
	function hook_save_record($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1)
	{

	    ?>
        <script>
            $(document).ready(function() {
                var thisLink = $('span.log-viewer-module');
                // $('a:contains("External Modules")').parent('span').append(thisLink);

                var emLink = $('a:contains("External Modules")');
                $('<br>').insertAfter(emLink); //<span>&nbsp;</span>");
                thisLink.insertAfter(emLink); // .appendTo(thisLink);
            });
        </script>
        <?php
        \Plugin::log("In the control center");

	}
}
