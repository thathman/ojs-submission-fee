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

		{fbvFormSection label="plugins.generic.submissionFee.settings.noticePlacement" list=true}
			{fbvElement type="checkbox" id="noticeSteps-details" name="noticeSteps[]" value="details" checked="details"|in_array:$noticeSteps label="plugins.generic.submissionFee.settings.steps.details"}
			{fbvElement type="checkbox" id="noticeSteps-files" name="noticeSteps[]" value="files" checked="files"|in_array:$noticeSteps label="plugins.generic.submissionFee.settings.steps.files"}
			{fbvElement type="checkbox" id="noticeSteps-contributors" name="noticeSteps[]" value="contributors" checked="contributors"|in_array:$noticeSteps label="plugins.generic.submissionFee.settings.steps.contributors"}
			{fbvElement type="checkbox" id="noticeSteps-editors" name="noticeSteps[]" value="editors" checked="editors"|in_array:$noticeSteps label="plugins.generic.submissionFee.settings.steps.editors"}
			{fbvElement type="checkbox" id="noticeSteps-reviewerSuggestions" name="noticeSteps[]" value="reviewerSuggestions" checked="reviewerSuggestions"|in_array:$noticeSteps label="plugins.generic.submissionFee.settings.steps.reviewerSuggestions"}
			{fbvElement type="checkbox" id="noticeSteps-review" name="noticeSteps[]" value="review" checked="review"|in_array:$noticeSteps label="plugins.generic.submissionFee.settings.steps.review"}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.submissionFee.settings.otherSurfaces" list=true}
			{fbvElement type="checkbox" id="ownStep" label="plugins.generic.submissionFee.settings.ownStep" checked=$ownStep}
			{fbvElement type="checkbox" id="showOnStart" label="plugins.generic.submissionFee.settings.showOnStart" checked=$showOnStart}
			{fbvElement type="checkbox" id="showOnComplete" label="plugins.generic.submissionFee.settings.showOnComplete" checked=$showOnComplete}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.submissionFee.settings.noticeTitle"}
			{fbvElement type="text" id="noticeTitle" value=$noticeTitle size=$fbvStyles.size.LARGE}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.submissionFee.settings.noticeBodyHardBlock"}
			{fbvElement type="textarea" id="noticeBodyHardBlock" value=$noticeBodyHardBlock rich=false height=$fbvStyles.height.SHORT}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.submissionFee.settings.noticeBodyHoldUntilPaid"}
			{fbvElement type="textarea" id="noticeBodyHoldUntilPaid" value=$noticeBodyHoldUntilPaid rich=false height=$fbvStyles.height.SHORT}
		{/fbvFormSection}

	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
</form>