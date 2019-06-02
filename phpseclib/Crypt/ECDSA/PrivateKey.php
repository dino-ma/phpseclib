<?php

/**
 * ECDSA Private Key
 *
 * @category  Crypt
 * @package   ECDSA
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib\Crypt\ECDSA;

use phpseclib\Crypt\ECDSA;
use phpseclib\Crypt\ECDSA\Signature\ASN1 as ASN1Signature;
use phpseclib\Math\BigInteger;
use phpseclib\Crypt\ECDSA\BaseCurves\TwistedEdwards as TwistedEdwardsCurve;
use phpseclib\Crypt\Hash;
use phpseclib\Crypt\ECDSA\Curves\Ed25519;
use phpseclib\Crypt\ECDSA\Keys\PKCS8;
use phpseclib\Crypt\Common;

/**
 * ECDSA Private Key
 *
 * @package ECDSA
 * @author  Jim Wigginton <terrafrost@php.net>
 * @access  public
 */
class PrivateKey extends ECDSA implements Common\PrivateKey
{
    use Common\PasswordProtected;

    /**
     * Private Key dA
     *
     * sign() converts this to a BigInteger so one might wonder why this is a FiniteFieldInteger instead of
     * a BigInteger. That's because a FiniteFieldInteger, when converted to a byte string, is null padded by
     * a certain amount whereas a BigInteger isn't.
     *
     * @var object
     */
    protected $dA;

    /**
     * Create a signature
     *
     * @see self::verify()
     * @access public
     * @param string $message
     * @return mixed
     */
    public function sign($message)
    {
        $dA = $this->dA->toBigInteger();

        $order = $this->curve->getOrder();

        if ($this->curve instanceof TwistedEdwardsCurve) {
            if ($this->curve instanceof Ed25519 && self::$engines['libsodium'] && !isset($this->context)) {
                return sodium_crypto_sign_detached($message, $this->toString('libsodium'));
            }

            // contexts (Ed25519ctx) are supported but prehashing (Ed25519ph) is not.
            // quoting https://tools.ietf.org/html/rfc8032#section-8.5 ,
            // "The Ed25519ph and Ed448ph variants ... SHOULD NOT be used"
            $A = $this->curve->encodePoint($this->QA);
            $curve = $this->curve;
            $hash = new Hash($curve::HASH);

            $secret = substr($hash->hash($this->dA->secret), $curve::SIZE);

            if ($curve instanceof Ed25519) {
                $dom = !isset($this->context) ? '' :
                    'SigEd25519 no Ed25519 collisions' . "\0" . chr(strlen($this->context)) . $this->context;
            } else {
                $context = isset($this->context) ? $this->context : '';
                $dom = 'SigEd448' . "\0" . chr(strlen($context)) . $context;
            }
            // SHA-512(dom2(F, C) || prefix || PH(M))
            $r = $hash->hash($dom . $secret . $message);
            $r = strrev($r);
            $r = new BigInteger($r, 256);
            list(, $r) = $r->divide($order);
            $R = $curve->multiplyPoint($curve->getBasePoint(), $curve->convertInteger($r));
            $R = $curve->encodePoint($R);
            $k = $hash->hash($dom . $R . $A . $message);
            $k = strrev($k);
            $k = new BigInteger($k, 256);
            list(, $k) = $k->divide($order);
            $S = $k->multiply($dA)->add($r);
            list(, $S) = $S->divide($order);
            $S = str_pad(strrev($S->toBytes()), $curve::SIZE, "\0");
            return $R . $S;
        }

        $shortFormat = $this->shortFormat;
        $format = $this->format;
        if ($format === false) {
            return false;
        }

        if (self::$engines['OpenSSL'] && in_array($this->hash->getHash(), openssl_get_md_methods())) {
            $namedCurves = PKCS8::isUsingNamedCurves();

            // use specified curves to avoid issues with OpenSSL possibly not supporting a given named curve;
            // doing this may mean some curve-specific optimizations can't be used but idk if OpenSSL even
            // has curve-specific optimizations
            PKCS8::useSpecifiedCurve();

            $signature = '';
            // altho PHP's OpenSSL bindings only supported ECDSA key creation in PHP 7.1 they've long
            // supported signing / verification
            $result = openssl_sign($message, $signature, $this->toString('PKCS8'), $this->hash->getHash());

            if ($namedCurves) {
                PKCS8::useNamedCurve();
            }

            if ($result) {
                if ($shortFormat == 'ASN1') {
                    return $signature;
                }

                extract(ASN1Signature::load($signature));

                return $shortFormat == 'SSH2' ? $format::save($r, $s, $this->getCurve()) : $format::save($r, $s);
            }
        }

        $e = $this->hash->hash($message);
        $e = new BigInteger($e, 256);

        $Ln = $this->hash->getLength() - $order->getLength();
        $z = $Ln > 0 ? $e->bitwise_rightShift($Ln) : $e;

        while (true) {
            $k = BigInteger::randomRange(self::$one, $order->subtract(self::$one));
            list($x, $y) = $this->curve->multiplyPoint($this->curve->getBasePoint(), $this->curve->convertInteger($k));
            $x = $x->toBigInteger();
            list(, $r) = $x->divide($order);
            if ($r->equals(self::$zero)) {
                continue;
            }
            $kinv = $k->modInverse($order);
            $temp = $z->add($dA->multiply($r));
            $temp = $kinv->multiply($temp);
            list(, $s) = $temp->divide($order);
            if (!$s->equals(self::$zero)) {
                break;
            }
        }

        // the following is an RFC6979 compliant implementation of deterministic ECDSA
        // it's unused because it's mainly intended for use when a good CSPRNG isn't
        // available. if phpseclib's CSPRNG isn't good then even key generation is
        // suspect
        /*
        // if this were actually being used it'd probably be better if this lived in load() and createKey()
        $this->q = $this->curve->getOrder();
        $dA = $this->dA->toBigInteger();
        $this->x = $dA;

        $h1 = $this->hash->hash($message);
        $k = $this->computek($h1);
        list($x, $y) = $this->curve->multiplyPoint($this->curve->getBasePoint(), $this->curve->convertInteger($k));
        $x = $x->toBigInteger();
        list(, $r) = $x->divide($this->q);
        $kinv = $k->modInverse($this->q);
        $h1 = $this->bits2int($h1);
        $temp = $h1->add($dA->multiply($r));
        $temp = $kinv->multiply($temp);
        list(, $s) = $temp->divide($this->q);
        */

        return $shortFormat == 'SSH2' ? $format::save($r, $s, $this->getCurve()) : $format::save($r, $s);
    }

    /**
     * Returns the private key
     *
     * @param string $type
     * @param array $options optional
     * @return string
     */
    public function toString($type, array $options = [])
    {
        $type = self::validatePlugin('Keys', $type, 'savePrivateKey');

        return $type::savePrivateKey($this->dA, $this->curve, $this->QA, $this->password, $options);
    }

    /**
     * Returns the public key
     *
     * @see self::getPrivateKey()
     * @access public
     * @return mixed
     */
    public function getPublicKey()
    {
        $type = self::validatePlugin('Keys', 'PKCS8', 'savePublicKey');

        $key = $type::savePublicKey($this->curve, $this->QA);
        $key = ECDSA::load($key, 'PKCS8')
            ->withHash($this->hash->getHash())
            ->withSignatureFormat($this->shortFormat);
        if ($this->curve instanceof TwistedEdwardsCurve) {
            $key = $key->withContext($this->context);
        }
        return $key;
    }
}
