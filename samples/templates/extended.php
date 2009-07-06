{!extends name="base"}

{block id=title}{t}Title modified {$var} a{/t}{/block}
{block id="empty"}
	{foreach list="$list" var="$n"}{$n}{/foreach}
{/block}