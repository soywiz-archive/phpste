<html>
	<head>
		<title>{block id=title}{t}hd.com{/t}{/block}</title>
	</head>
	<body>{block id=body}
		{blockdef id=test}{t}Hola{/t}{/blockdef}

		{for var="$n" from="0" to="10" text="hola, esto es una prueba" text2="hola"}
			{$n}: {!putblock id=test}
		{/for}
	{/block}</body>
</html>