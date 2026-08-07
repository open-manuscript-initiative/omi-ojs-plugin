<script>
$(function() {
    $('#studioIntegrationSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
});
</script>
<form class="pkp_form" id="studioIntegrationSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
    {csrf}
    {fbvFormArea id="studioIntegrationSettings" title="plugins.generic.studioIntegration.settings.title"}
        {fbvFormSection title="plugins.generic.studioIntegration.settings.studioUrl" required=true}
            {fbvElement type="text" id="studioUrl" value=$studioUrl required=true size=$fbvStyles.size.LARGE}
        {/fbvFormSection}
        {fbvFormSection title="plugins.generic.studioIntegration.settings.installationId"}
            {fbvElement type="text" id="installationId" value=$installationId size=$fbvStyles.size.LARGE}
        {/fbvFormSection}
        {fbvFormSection title="plugins.generic.studioIntegration.settings.sharedSecret"}
            {fbvElement type="text" id="sharedSecret" value=$sharedSecret size=$fbvStyles.size.LARGE}
        {/fbvFormSection}
        {fbvFormSection title="plugins.generic.studioIntegration.settings.tokenTtl"}
            {fbvElement type="text" id="tokenTtl" value=$tokenTtl size=$fbvStyles.size.SMALL}
        {/fbvFormSection}
    {/fbvFormArea}
    {fbvFormButtons submitText="common.save" hideCancel=true}
</form>
