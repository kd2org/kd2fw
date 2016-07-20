{foreach ($loop as $k=>$v)}
	{$k} = {$v} (iteration {$iteration})
{/foreach}

{foreach from=$loop item="v" key="k"}
	{$k} = {$v} (iteration {$iteration})
{/foreach}

{foreach from=$loop item="v"}
	{$v} (iteration {$iteration})
{/foreach}

{foreach from=$empty_loop item="v"}
	{$v}
{foreachelse}
	Empty loop
{/foreach}