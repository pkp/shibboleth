<div>	
	<h2>
		{if trim($shibbolethTitle) != ""}
			{if $isRegistration}
				{translate key="plugins.generic.shibboleth.manager.settings.Register"}
			{/if}
			{$shibbolethTitle}
		{else}
			{if $isRegistration}
				{translate key="plugins.generic.shibboleth.manager.settings.Register"}
			{/if}
			{translate key="plugins.generic.shibboleth.manager.settings.InstitutionalLogin"}
		{/if}
	</h2>
	{if trim($shibbolethDescription) != ""}
		<p>{$shibbolethDescription}</p>
	{/if}
	<a href="{$shibbolethLoginUrl}" class="cmp_button">
		{if trim($shibbolethButtonLabel) != ""}
			{$shibbolethButtonLabel}
		{else}
			{translate key="plugins.generic.shibboleth.manager.settings.InstitutionalLogin"}
		{/if}
	</a>
</div>
<h2>
	{if $isRegistration}
		{translate key="plugins.generic.shibboleth.manager.settings.Register"}
	{/if}
	{translate key="plugins.generic.shibboleth.manager.settings.LocalLogin"}
</h2>
