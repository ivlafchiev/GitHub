<?php
/**
 * A tiny stand-in for Stripe, for tests only (the real Stripe can't be
 * reached from the test environment).
 *
 *   php -S localhost:12111 tests/stripe-mock/server.php
 *
 * State lives in STRIPE_MOCK_DIR (default /tmp/flexo-stripe-mock):
 *   config.json   { "webhook_url": "...", "webhook_secret": "whsec_..." }
 *   sessions/*.json, requests.log (one JSON line per API request)
 *
 * Stripe API subset:
 *   POST /v1/checkout/sessions                 create (honours Idempotency-Key)
 *   POST /v1/checkout/sessions/{id}/expire     expire an open session
 * Hosted page and test controls:
 *   GET  /pay/{id}                             payment page (Pay / Decline / Cancel)
 *   POST /pay/{id}/complete                    pay → signed checkout.session.completed → success_url
 *   POST /pay/{id}/decline                     signed payment_intent.payment_failed, stays on the page
 *   GET  /pay/{id}/cancel                      back to cancel_url
 *   POST /_control/expire/{id}                 signed checkout.session.expired
 *   POST /_control/refund/{id}?amount=5000     signed charge.refunded (amount in cents, cumulative)
 *   GET  /_control/session/{id}                session JSON
 */

$dir = getenv( 'STRIPE_MOCK_DIR' ) ?: sys_get_temp_dir() . '/flexo-stripe-mock';
@mkdir( $dir . '/sessions', 0777, true );
$config = is_file( $dir . '/config.json' ) ? json_decode( file_get_contents( $dir . '/config.json' ), true ) : array();
$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$method = $_SERVER['REQUEST_METHOD'];

function mock_json( $data, $code = 200 ) {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
	exit;
}

function mock_session( $dir, $id ) {
	$file = $dir . '/sessions/' . basename( $id ) . '.json';
	return is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
}

function mock_save( $dir, array $session ) {
	file_put_contents( $dir . '/sessions/' . $session['id'] . '.json', json_encode( $session ) );
}

function mock_webhook( array $config, $type, array $object, $livemode = false ) {
	if ( empty( $config['webhook_url'] ) ) {
		return 0;
	}
	$event   = array(
		'id'       => 'evt_' . bin2hex( random_bytes( 8 ) ),
		'object'   => 'event',
		'type'     => $type,
		'livemode' => $livemode,
		'created'  => time(),
		'data'     => array( 'object' => $object ),
	);
	$payload = json_encode( $event );
	$t       = time();
	$sig     = hash_hmac( 'sha256', $t . '.' . $payload, (string) $config['webhook_secret'] );
	$ch      = curl_init( $config['webhook_url'] );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json', 'Stripe-Signature: t=' . $t . ',v1=' . $sig ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_PROXY          => '',
		)
	);
	curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	return $code;
}

$host = ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost:12111' );

// ---- Stripe API ----------------------------------------------------------
if ( 0 === strpos( $path, '/v1/' ) ) {
	file_put_contents( $dir . '/requests.log', json_encode( array( 'method' => $method, 'path' => $path, 'post' => $_POST, 'auth' => isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '', 'idempotency' => isset( $_SERVER['HTTP_IDEMPOTENCY_KEY'] ) ? $_SERVER['HTTP_IDEMPOTENCY_KEY'] : '' ) ) . "\n", FILE_APPEND );
	$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
	if ( ! preg_match( '/^Bearer (sk|rk)_(test|live)_/', $auth ) ) {
		mock_json( array( 'error' => array( 'message' => 'Invalid API Key provided' ) ), 401 );
	}
	if ( 'POST' === $method && '/v1/checkout/sessions' === $path ) {
		$idem = isset( $_SERVER['HTTP_IDEMPOTENCY_KEY'] ) ? $_SERVER['HTTP_IDEMPOTENCY_KEY'] : '';
		if ( $idem && is_file( $dir . '/idem-' . md5( $idem ) ) ) {
			mock_json( mock_session( $dir, trim( file_get_contents( $dir . '/idem-' . md5( $idem ) ) ) ) );
		}
		$item    = $_POST['line_items'][0]['price_data'];
		$id      = 'cs_test_' . bin2hex( random_bytes( 12 ) );
		$session = array(
			'id'                  => $id,
			'object'              => 'checkout.session',
			'url'                 => 'http://' . $host . '/pay/' . $id,
			'amount_total'        => (int) $item['unit_amount'] * (int) $_POST['line_items'][0]['quantity'],
			'currency'            => $item['currency'],
			'status'              => 'open',
			'payment_status'      => 'unpaid',
			'payment_intent'      => null,
			'client_reference_id' => isset( $_POST['client_reference_id'] ) ? $_POST['client_reference_id'] : null,
			'customer_email'      => isset( $_POST['customer_email'] ) ? $_POST['customer_email'] : null,
			'metadata'            => isset( $_POST['metadata'] ) ? $_POST['metadata'] : array(),
			'pi_metadata'         => isset( $_POST['payment_intent_data']['metadata'] ) ? $_POST['payment_intent_data']['metadata'] : array(),
			'success_url'         => $_POST['success_url'],
			'cancel_url'          => $_POST['cancel_url'],
			'expires_at'          => (int) $_POST['expires_at'],
			'name'                => $item['product_data']['name'],
			'livemode'            => false !== strpos( $auth, '_live_' ),
		);
		mock_save( $dir, $session );
		if ( $idem ) {
			file_put_contents( $dir . '/idem-' . md5( $idem ), $id );
		}
		mock_json( $session );
	}
	if ( 'POST' === $method && preg_match( '#^/v1/checkout/sessions/([^/]+)/expire$#', $path, $m ) ) {
		$session = mock_session( $dir, $m[1] );
		if ( ! $session ) {
			mock_json( array( 'error' => array( 'message' => 'No such checkout.session' ) ), 404 );
		}
		if ( 'open' !== $session['status'] ) {
			mock_json( array( 'error' => array( 'message' => 'Only open sessions can be expired.' ) ), 400 );
		}
		$session['status'] = 'expired';
		mock_save( $dir, $session );
		mock_json( $session );
	}
	mock_json( array( 'error' => array( 'message' => 'Unknown endpoint ' . $path ) ), 404 );
}

// ---- Hosted payment page -----------------------------------------------------
if ( preg_match( '#^/pay/([^/]+)(?:/(complete|decline|cancel))?$#', $path, $m ) ) {
	$session = mock_session( $dir, $m[1] );
	if ( ! $session ) {
		http_response_code( 404 );
		exit( 'No such session' );
	}
	$action = isset( $m[2] ) ? $m[2] : '';
	if ( 'cancel' === $action ) {
		header( 'Location: ' . $session['cancel_url'], true, 303 );
		exit;
	}
	if ( 'complete' === $action && 'POST' === $method ) {
		if ( 'open' !== $session['status'] ) {
			http_response_code( 410 );
			exit( 'This payment page has expired.' );
		}
		$session['status']         = 'complete';
		$session['payment_status'] = 'paid';
		$session['payment_intent'] = 'pi_test_' . bin2hex( random_bytes( 10 ) );
		mock_save( $dir, $session );
		mock_webhook( $config, 'checkout.session.completed', $session, $session['livemode'] );
		header( 'Location: ' . $session['success_url'], true, 303 );
		exit;
	}
	$note = '';
	if ( 'decline' === $action && 'POST' === $method ) {
		mock_webhook(
			$config,
			'payment_intent.payment_failed',
			array(
				'id'                 => 'pi_test_declined_' . bin2hex( random_bytes( 6 ) ),
				'object'             => 'payment_intent',
				'amount'             => $session['amount_total'],
				'currency'           => $session['currency'],
				'metadata'           => $session['pi_metadata'],
				'last_payment_error' => array( 'message' => 'Your card was declined.' ),
			),
			$session['livemode']
		);
		$note = '<p class="declined" role="alert">Your card was declined.</p>';
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	$amount = number_format( $session['amount_total'] / 100, 2 ) . ' ' . strtoupper( $session['currency'] );
	echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Stripe mock checkout</title></head><body>';
	echo '<h1>Mock Stripe Checkout</h1><p class="product">' . htmlspecialchars( $session['name'] ) . '</p><p class="amount">' . htmlspecialchars( $amount ) . '</p>' . $note;
	echo '<form method="post" action="/pay/' . $session['id'] . '/complete"><button id="pay">Pay ' . htmlspecialchars( $amount ) . '</button></form>';
	echo '<form method="post" action="/pay/' . $session['id'] . '/decline"><button id="decline">Use a declined card</button></form>';
	echo '<a id="cancel" href="/pay/' . $session['id'] . '/cancel">← Back</a></body></html>';
	exit;
}

// ---- Test controls -------------------------------------------------------------
if ( preg_match( '#^/_control/(expire|refund|session)/([^/]+)$#', $path, $m ) ) {
	$session = mock_session( $dir, $m[2] );
	if ( ! $session ) {
		mock_json( array( 'error' => 'No such session' ), 404 );
	}
	if ( 'session' === $m[1] ) {
		mock_json( $session );
	}
	if ( 'expire' === $m[1] ) {
		$session['status'] = 'expired';
		mock_save( $dir, $session );
		mock_json( array( 'webhook' => mock_webhook( $config, 'checkout.session.expired', $session, $session['livemode'] ) ) );
	}
	$amount = isset( $_GET['amount'] ) ? (int) $_GET['amount'] : $session['amount_total'];
	$charge = array(
		'id'              => 'ch_' . substr( $session['payment_intent'], 3 ),
		'object'          => 'charge',
		'amount'          => $session['amount_total'],
		'amount_refunded' => $amount,
		'currency'        => $session['currency'],
		'payment_intent'  => $session['payment_intent'],
		'refunded'        => $amount >= $session['amount_total'],
		'metadata'        => array(),
	);
	mock_json( array( 'webhook' => mock_webhook( $config, 'charge.refunded', $charge, $session['livemode'] ) ) );
}

http_response_code( 404 );
echo 'Not found';
