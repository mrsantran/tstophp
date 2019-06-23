About
-----
TP-to-PHP converter convert TypeScript definition to PHP code

Installation and requirements
-----------------------------

The best way how to install TP-to-PHP is use a Composer:

```
php composer.phar require santran/tstophp
```

Usage
-----

```php
try {
    \tptophp\Converter::convert($tsFile, $phpFile);
}catch (\tptophp\UnexpectedSyntaxException $e){
	echo $e->getFullMessage();
}
```
