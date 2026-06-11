{**
 * settings.tpl - Submission fee plugin settings
 *}
<script>
	$(function() {ldelim}
		$('#submissionFeeSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="submissionFeeSettings"
	method="POST"
	action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{fbvFormArea id="submissionFeeArea"}

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="feeEnabled" label="plugins.generic.submissionFee.settings.enabled" checked=$feeEnabled}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.submissionFee.settings.amount"}
			{fbvElement type="text" id="amount" value=$amount size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.submissionFee.settings.currency"}
			{fbvElement type="text" id="currency" value=$currency size=$fbvStyles.size.SMALL placeholder="NGN"}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.submissionFee.settings.mode" list=true}
			{fbvElement type="radio" id="mode-hardBlock" name="mode" value="hardBlock" checked=$mode|compare:"hardBlock" label="plugins.generic.submissionFee.settings.mode.hardBlock"}
			{fbvElement type="radio" id="mode-holdUntilPaid" name="mode" value="holdUntilPaid" checked=$mode|compare:"holdUntilPaid" label="plugins.generic.submissionFee.settings.mode.holdUntilPaid"}
		{/fbvFormSection}

	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
</form>