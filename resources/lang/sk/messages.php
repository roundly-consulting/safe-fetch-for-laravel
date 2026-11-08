<?php

declare(strict_types=1);

return [
    'invalid_url' => 'URL adresu nie je možné načítať: nie je platná.',
    'scheme' => 'URL adresu nie je možné načítať: jej schéma nie je povolená.',
    'credentials' => 'URL adresu nie je možné načítať: nesmie obsahovať používateľské meno ani heslo.',
    'non_canonical_host' => 'URL adresu nie je možné načítať: jej hostiteľ nie je zapísaný v štandardnom tvare.',
    'port' => 'URL adresu nie je možné načítať: jej port nie je povolený.',
    // blocked and unresolved read the same: telling them apart would map the internal network.
    'blocked' => 'URL adresu nie je možné načítať: jej cieľ nie je povolený alebo nie je dostupný.',
    'unresolved' => 'URL adresu nie je možné načítať: jej cieľ nie je povolený alebo nie je dostupný.',
    'not_allowed' => 'URL adresu nie je možné načítať: jej hostiteľ nie je v zozname povolených hostiteľov.',
    'cannot_pin' => 'Požiadavka nebola odoslaná: jej spojenie sa nepodarilo zabezpečiť na overený cieľ.',
    'too_many_redirects' => 'URL adresu nie je možné načítať: presmerováva príliš veľakrát.',
    'redirect_downgrade' => 'URL adresu nie je možné načítať: presmerováva zo zabezpečenej na nezabezpečenú adresu.',
    'timeout' => 'URL adresu teraz nie je možné načítať: server neodpovedal včas.',
    'status' => 'URL adresu nie je možné načítať: server odpovedal stavom :status.',
    'too_large' => 'URL adresu nie je možné načítať: odpoveď je príliš veľká.',
    'connection' => 'URL adresu teraz nie je možné načítať: spojenie so serverom zlyhalo.',
    'tls' => 'URL adresu nie je možné načítať: certifikát servera sa nepodarilo overiť.',
    'protocol' => 'URL adresu nie je možné načítať: odpoveď servera sa nepodarilo prečítať.',
];
