<?php

use KD2\Test;
use KD2\Graphics\SVG\Avatar;

require __DIR__ . '/../_assert.php';

test_avatar();

function test_avatar()
{
	$expected = <<<EOF
		<svg
			viewBox="0 0 36 36"
			fill="none"
			role="img"
			xmlns="http://www.w3.org/2000/svg"
			width="36"
			height="36"
		>
			<mask id="mask-721a9b52bfceacc503c056e3b9b93cfa" maskUnits="userSpaceOnUse" x="0" y="0" width="36" height="36">
				<rect width="36" height="36" rx="72" fill="#FFFFFF" />
			</mask>
			<g mask="url('#mask-721a9b52bfceacc503c056e3b9b93cfa')">
				<rect width="36" height="36" fill="#0c9" />
				<rect
					x="0"
					y="0"
					width="36"
					height="36"
					transform="translate(2 6) rotate(242 18 18) scale(1.2)"
					fill="#6f0"
					rx="36"
				/>
				<g transform="translate(-2 2) rotate(-2 18 18)">
					<path d="M13 24c4 2 8 2 12 0" stroke="#000000" fill="none" strokeLinecap="round" />
					<rect
						x="12"
						y="14"
						width="4"
						height="6"
						rx="3"
						stroke="none"
						fill="#000000"
					/>
					<rect
						x="22"
						y="14"
						width="4"
						height="6"
						rx="3"
						stroke="none"
						fill="#000000"
					/>
				</g>
			</g>
		</svg>
EOF;

	$avatar = Avatar::beam('coucou');
	Test::strictlyEquals(trim($expected), trim($avatar));
}
