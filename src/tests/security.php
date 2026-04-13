<?php

require __DIR__ . '/_assert.php';

use KD2\Security;
use KD2\Test;

assert(
	count(explode(' ', Security::getRandomPassphrase('/usr/share/dict/words', 10, '\w\'', true))) == 10
);

if (Security::canUseEncryption())
{
	$key = '-----BEGIN PGP PUBLIC KEY BLOCK-----
	Version: GnuPG v1

	mQENBFiKdc0BCACgAj2oj54eIpIJjXW8qFdZ5Vlb1f/x+Cj+MiIe/y/fSiW3+GGw
	iQR1j7mOGodciYevWCkaHMLnmYB8rSO8bp1C3XP+UVmSS10Q/kID7+XOUVyYs3Rd
	vNMDbSOxGfzNUBb+QR/+bSmz0PyBHrQf/6JDv1e3lK/XmOLTiKZQyCj/JR/pRcec
	9heXmQTOKwZSUfRJFUQGia4FoZSSvKjJEaI0CR7369mFV/pGdhy83UtDgywBr8SC
	dEg+zmS4OSruj5z9OAoRQJcYoqTgwn8J7//b9Dr/sWblDHqpLSOGg+Mewe3WqekK
	yV9l8qbnbtFkfZJqFJbusESo8ZulVMtoqWX3ABEBAAG0BVByb3V0iQE4BBMBAgAi
	BQJYinXNAhsDBgsJCAcDAgYVCAIJCgsEFgIDAQIeAQIXgAAKCRDUZQm4x6FsjhNW
	B/0Sc7IZKMvKqIDfGz4jU8WGlmx83mIIbfS03Pvz6PXlh0kYbS7uiZ0KagLH3s1w
	CGRZ3kxXnVs4Pp2eyF/KuqTemwhLxtvDHuTlCd0F4XVlftX5SqeNaffdxGfWHxWm
	Vi8crva5pHnPccP48QuVSUicMtThygJmpThcCu5ddvllu5v13Y4pN7akdj7nRzXJ
	2PD7E54EwAbr07Y2ZTvOZffcfgELiXcK/HbzB5w7t8LVMm8NrTDJqz1Or3OIeqGW
	rLu5DhZbUNp38+SVbZabFXL9ZqhLueiwiEbV68J58J5tZwvdc18Kj8HqhC5ZgM+4
	KoL71ZGcom43wxi522KLYogEuQENBFiKdc0BCADUweDxNDEhjhvCd8JYYjz/YolK
	TGhxcNBo/KluSCCprN9lKVlS/gadQQGsFIzrclNMmi1R12xswAS6zMAGiJjLRwYa
	c5GpCN9on2kg3uy9+2kpTteGj7Z6Uh62rezF2wOH6+PrwXSG5uCVA5LVpxyCwod+
	nm3zFMk+W34xVRGwBobi2nklOtjWaoY676y/NECh6vSkjKl2turtE+QJAIKco5so
	08SB1PqgfwNrsjJPxwcyLY3tDzlWuRU3Xb/Z5hNmMK3w+I3hsWsJBQLamMN7x5sm
	HzBhI33ioTu6hMzeg4j8Nyckmxfv5uC1jawB2GCCwMHvuYSUYX85sPHpiFehABEB
	AAGJAR8EGAECAAkFAliKdc0CGwwACgkQ1GUJuMehbI5U8Af+MUwlOq6e1pyPgcws
	96NtuNxqnngZKu1I1376aj08sJ7fVAHdwdxSLU5O2WmqRVDMBvsW3nFQbREufICe
	bJXBALfloloMLJwBPTtBqwZUr6kploclPUdrfRqoOa895LwXjys2+TNFtW3xDx62
	54jI/g+9mJxuitHJDQQPc+QPa5AmUqduyAdEXYkhc5pkX56vVIRFZUcDUhwfSPji
	9ZB2xmIOBuxXpk1GHcc/j/ZeYQDYmIgLSGE0UygzxxFTWobwiBacfGr6Mzgz52e2
	ynzjtZisS5vXzBRQmcuyLxzuOaR38prm2RrEfDxYukU2qOyfOQLViWzL/t0fUack
	v83u+g==
	=q5jY
	-----END PGP PUBLIC KEY BLOCK-----';

	$fingerprint = Security::getEncryptionKeyFingerprint($key);

	assert($fingerprint === '3FEA243C46A14FF604DF4912D46509B8C7A16C8E');

	$data = Security::encryptWithPublicKey($key, 'Bla');

	assert(strlen($data) > 0);
}
else
{
	echo 'Can\'t test GPG encryption: extension not available.' . PHP_EOL;
}