<?php

use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\SignData;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\Factory\TxBuilder;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use Nbobtc\Bitcoind\Bitcoind;
use Nbobtc\Bitcoind\Client;

require __DIR__ . "/vendor/autoload.php";

$testnet = true;

Bitcoin::setNetwork($testnet ? NetworkFactory::bitcoinTestnet() : NetworkFactory::bitcoin());

$bitcoindHost = (getenv('BITCOIN_RPC_HOST') ?: 'localhost');
$bitcoindUser = (getenv('BITCOIN_RPC_USER') ?: 'bitconrpcuser');
$bitcoindPassword = (getenv('BITCOIN_RPC_PASSWORD') ?: 'bitconrpcpassword');
$bitcoindPort = $testnet ? "18332" : "8332";
$bitcoindSSL = getenv('BITCOIN_RPC_SSL') ? json_decode(getenv('BITCOIN_RPC_SSL')) : false;
$bitcoindProtocol = $bitcoindSSL ? "https" : "http";

$bitcoindClient = new Client("{$bitcoindProtocol}://{$bitcoindUser}:{$bitcoindPassword}@{$bitcoindHost}:$bitcoindPort");
$bitcoind = new Bitcoind($bitcoindClient);

function getFeePerKB(Client $bitcoindClient, $fallback = false) {
    for ($i = 2; $i < 10; $i++) {
        $response = $bitcoindClient->execute('estimatefee', $i);
        if ($response->result > 0) {
            return $response->result;
        }
    }

    if ($fallback) {
        return $fallback;
    }
}

$address = "mvXw4EEuRZtk8jZHuo3P3soNvToCfBoc38";

// fetch UTXOs
$utxos = $bitcoind->listunspent(1, 999999, [$address]);

// convert floats to ints (BTC to satoshi)
$utxos = array_map(function($utxo) {
    $utxo->amount = (int)($utxo->amount * 1e8);
    return $utxo;
}, $utxos);

// pick one or more UTXOs to split
//  logic in this example sorts by value and just uses $n highest
$n = 2;
array_multisort(array_map(function($utxo) { return $utxo->amount; }, $utxos), SORT_DESC, $utxos);
$utxos = array_slice($utxos, 0, $n);
var_dump($utxos);

$inputSum = array_sum(array_map(function($utxo) { return $utxo->amount; }, $utxos));
var_dump("inputSum: {$inputSum}");

// get fee per kb with 0.0005 fallback
$feePerKb = getFeePerKB($bitcoindClient, 50000);
var_dump("feePerKb: {$feePerKb}");

// how many UTXOs we want to split it into
$m = 10;

// estimate size of TX
$estimatedSize = 16 + (count($utxos) * 148) + ($m * 34);
var_dump("estimatedSize: {$estimatedSize}");

$estimatedFee = ceil($estimatedSize * ($feePerKb / 1000));
var_dump("estimatedFee: {$estimatedFee}");

$estimatedSpendable = $inputSum - $estimatedFee;
var_dump("estimatedSpendable: {$estimatedSpendable}");

// init txbuilder
$txb = new TxBuilder();

// add utxos to spend
foreach ($utxos as $utxo) {
    $txb->spendOutPoint(new OutPoint(Buffer::hex($utxo->txid), $utxo->vout));
}

// create $m outputs
$v = (int)floor($estimatedSpendable / $m);
for ($i = 0; $i < $m; $i++) {
    $txb->payToAddress($v, AddressFactory::fromString($address));
}

///*
// sign usign bitcoin-php
$privKey = PrivateKeyFactory::fromWif("cNY1ETfSYuavn5Nqpo4rwh932Bxd3R3mnWiuFa8TedVJqhdy88qF");
$signer = (new Signer($txb->get(), Bitcoin::getEcAdapter()));

foreach ($utxos as $i => $utxo) {
    $signData = new SignData();
    $input = $signer->input($i, new TransactionOutput($utxo->amount, ScriptFactory::fromHex($utxo->scriptPubKey)), $signData);
    $input->sign($privKey);
}

$signed = $signer->get();
$signedTxHex = $signed->getHex();

//*/

/*
// sign using bitcoind
$txInfo = array_map(function($utxo) {
    return [
        'txid' => $utxo->txid,
        'vout' => $utxo->vout,
        'scriptPubKey' => $utxo->scriptPubKey,
    ];
}, $utxos);

var_dump($txb->get()->getHex());
$signed = $bitcoind->signrawtransaction($txb->get()->getHex(), $txInfo, ['cNY1ETfSYuavn5Nqpo4rwh932Bxd3R3mnWiuFa8TedVJqhdy88qF']);
var_dump($signed);

if (!$signed->complete) {
    throw new \Exception("Not completely signed");
}

$signedTxHex = $signed->hex;
//*/
var_dump($signedTxHex);

// prompt to broadcast
$sendok = \readline("broadcast it? [y/N]");
if ($sendok && in_array(strtolower($sendok, ['y', 'yes']))) {
    var_dump($bitcoind->sendrawtransaction($signedTxHex));
}
