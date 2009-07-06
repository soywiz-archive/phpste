<html>
	<head>
		<title>{block id=title}{t}hd.com{/t}{/block}</title>
	</head>
	<body>{block id=body}
		{blockdef id=test}{t}Hola{/t}{/blockdef}

		{for var="$n" from="0" to="10"}
			{$n}: {!putblock id=test}

		{/for}

		{block id="empty"}{/block}
	{/block}</body>
</html>