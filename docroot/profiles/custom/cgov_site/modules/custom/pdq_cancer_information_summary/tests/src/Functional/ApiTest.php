<?php

namespace Drupal\Tests\pdq_cancer_information_summary\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Verify publication of PDQ Cancer Information Summaries.
 */
class ApiTest extends BrowserTestBase {

  /**
   * Use our own profile instead of the default.
   *
   * @var string
   */
  protected $profile = 'cgov_site';

  /**
   * Basic authentication credentials.
   *
   * @var array
   */
  protected $auth;

  /**
   * Test English Cancer Information Summary document.
   *
   * @var array
   */
  protected $english = [
    'nid' => NULL,
    'title' => 'Test English Summary',
    'short_title' => 'Test Short Title',
    'description' => 'Test description',
    'language' => 'en',
    'url' => '/test/url',
    'cdr_id' => 5001,
    'audience' => 'Patients',
    'summary_type' => 'Treatment',
    // Begin suppressed field.
    // 'keywords' => 'cancer, treatment',
    // End suppressed field.
    'posted_date' => '2020-01-01',
    'updated_date' => '2020-01-02',
    'sections' => [
      ['id' => '_1', 'title' => 'Section 1', 'html' => '<p>one</p>'],
      ['id' => '_2', 'title' => 'Section 2', 'html' => '<p>two</p>'],
    ],
  ];

  /**
   * Test Spanish Cancer Information Summary document.
   *
   * @var array
   */
  protected $spanish = [
    'nid' => NULL,
    'title' => "Resumen en espa\u{f1}ol",
    'short_title' => "T\u{ed}tulo corto",
    'description' => "Descripci\u{f3}n",
    'language' => 'es',
    'url' => '/expanol/test/url',
    'cdr_id' => 5002,
    'audience' => 'Patients',
    'summary_type' => 'Treatment',
    // Begin suppressed field.
    // 'keywords' => 'cancer, treatment',
    // End suppressed field.
    'posted_date' => '2020-01-04',
    'updated_date' => '2020-01-05',
    'sections' => [
      ['id' => '_1', 'title' => "Secci\u{f3}n 1", 'html' => '<p>Uno</p>'],
      ['id' => '_2', 'title' => "Secci\u{f3}n 2", 'html' => '<p>Dos</p>'],
    ],
  ];

  /**
   * Names of fields which can be compared in a loop.
   *
   * @var array
   */
  protected $fields = [
    'audience',
    'cdr_id',
    'description',
    // Begin suppressed field.
    // 'keywords',
    // End suppressed field.
    'posted_date',
    'title',
    'sections',
    'short_title',
    'summary_type',
    'updated_date',
    'url',
  ];

  /**
   * URL for common PDQ API requests.
   *
   * @var string
   */
  protected $pdqUrl;

  /**
   * URL for PDQ Cancer Information Summary API requests.
   *
   * @var string
   */
  protected $cisUrl;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Build the URLs for the API requests.
    $url = Url::fromUri('base:pdq/api/cis');
    $this->cisUrl = $url->setAbsolute(TRUE)->toString();
    $url = Url::fromUri('base:pdq/api');
    $this->pdqUrl = $url->setAbsolute(TRUE)->toString();

    // Create a user that can import the PDQ content.
    $user = $this->drupalCreateUser();
    $user->addRole('pdq_importer');
    $user->save();
    $this->auth = [$user->name->value, $user->passRaw];
  }

  /**
   * Verify correct behavior of PDQ Cancer Information Summary APIs.
   */
  public function testApis() {

    // Attempt to create the Spanish summary first (should fail).
    $payload = $this->store($this->spanish, 400);
    $expected = 'New summary node must be the English version';
    $this->assertEqual($payload['message'], $expected);

    // Store new English summary and capture the node ID.
    $payload = $this->store($this->english, 201);
    $nid = $this->english['nid'] = $this->spanish['nid'] = $payload['nid'];

    // Verify that the node ID lookup works correctly.
    $matches = $this->findNodes($this->english['cdr_id']);
    $this->assertEqual($matches, [[$nid, 'en']]);

    // Confirm that the values have been stored correctly.
    $values = $this->fetchNode($nid);
    $this->assertFalse($values['en']['published'], 'Not yet published');
    $this->checkValues($values, ['en']);

    // Store a modified revision (still unpublished).
    $section = ['id' => '_3', 'title' => 'Section 3', 'html' => '<p>3</p>'];
    $this->english['sections'][] = $section;
    $this->english['description'] = 'Revised test description';
    $payload = $this->store($this->english, 200);
    $this->assertEqual($payload['nid'], $nid, 'Uses same node');

    // Confirm that the values are still stored correctly.
    $values = $this->fetchNode($nid);
    $this->assertFalse($values['en']['published'], 'Not yet published');
    $this->checkValues($values, ['en']);

    // Add the Spanish translation.
    $payload = $this->store($this->spanish, 200);
    $this->assertEqual($payload['nid'], $nid, 'Uses same node');

    // Verify that the node ID lookup still works correctly.
    $matches = $this->findNodes($this->spanish['cdr_id']);
    $this->assertEqual($matches, [[$nid, 'es']]);
    $matches = $this->findNodes($this->english['cdr_id']);
    $this->assertEqual($matches, [[$nid, 'en']]);

    // Confirm that the values have been stored correctly.
    $values = $this->fetchNode($nid);
    $this->assertFalse($values['en']['published'], 'Not yet published');
    $this->assertFalse($values['es']['published'], 'Not yet published');
    $this->checkValues($values, ['en', 'es']);

    // Publish the summaries and make sure they're still intact.
    $this->publish();
    $values = $this->fetchNode($nid);
    $this->assertTrue($values['en']['published'], 'Published');
    $this->assertTrue($values['es']['published'], 'Published');
    $this->checkValues($values, ['en', 'es']);

    // Try to delete the English summary (should fail).
    $this->delete($this->english, FALSE);

    // Delete the Spanish summary.
    $this->delete($this->spanish, TRUE);

    // Now it should be possible to delete the English summary.
    $this->delete($this->english, TRUE);
  }

  /**
   * Helper method to submit an HTTP request.
   *
   * @param string $method
   *   HTTP verb (e.g., 'POST') for request.
   * @param string $url
   *   Web address to which request should be directed.
   * @param array $options
   *   Values to be passed the $client->request() (['json' => $data] for
   *   POST requests; empty array -- the default -- otherwise).
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   Object representing response from server.
   */
  private function request(string $method, string $url, array $options = []) {
    $options['auth'] = $this->auth;
    $options['query'] = ['_format' => 'json'];
    $options['http_errors'] = FALSE;
    $options['allow_redirects'] = FALSE;
    $client = $this->getHttpClient();
    return $client->request($method, $url, $options);
  }

  /**
   * Send values for a PDQ Cancer Information Summary to the CMS.
   *
   * @param array $values
   *   Summary values to be stored.
   * @param int $expected
   *   Status value which should result from request.
   *
   * @return array
   *   Array with node ID (indexed by 'nid') if successful; error message
   *   (indexed by 'message') otherwise.
   */
  private function store(array $values, $expected) {
    $response = $this->request('POST', $this->cisUrl, ['json' => $values]);
    $this->assertEqual($response->getStatusCode(), $expected);
    return json_decode($response->getBody()->__toString(), TRUE);
  }

  /**
   * Map CDR ID for PDQ summary to Drupal nodes.
   *
   * @param int $cdr_id
   *   Document ID for PDQ summary.
   *
   * @return array
   *   Pairs of node ID and language code (must be only one pair).
   */
  private function findNodes($cdr_id) {
    $response = $this->request('GET', "$this->pdqUrl/$cdr_id");
    $this->assertEqual($response->getStatusCode(), 200);
    $pairs = json_decode($response->getBody()->__toString(), TRUE);
    $this->assertCount(1, $pairs, 'Only one node/language per summary');
    $this->assertCount(2, $pairs[0], 'Pair must have two items');
    list($nid, $language) = $pairs[0];
    $this->assertTrue(is_numeric($nid), 'Node ID is numeric');
    $this->assertEqual($nid, (int) $nid, 'Node ID is an integer');
    $this->assertContains($language, ['en', 'es'], 'Valid language code');
    return $pairs;
  }

  /**
   * Get the current values for a given node.
   *
   * @param int $nid
   *   Unique node ID.
   *
   * @return array
   *   Values for the requested node (all languages).
   */
  private function fetchNode($nid) {
    $response = $this->request('GET', "$this->cisUrl/$nid");
    $this->assertEqual($response->getStatusCode(), 200);
    $values = json_decode($response->getBody()->__toString(), TRUE);
    $this->assertEqual($values['nid'], $nid);
    return $values;
  }

  /**
   * Verify that the retrieved values match what we stored.
   *
   * @param array $values
   *   Values retrieved from the CMS for a given node.
   * @param array $languages
   *   Languages which should be present in the node.
   */
  private function checkValues(array $values, array $languages) {
    $translations = ['en' => $this->english, 'es' => $this->spanish];
    foreach ($translations as $code => $expected) {
      if (in_array($code, $languages)) {
        $this->assertEqual($values['nid'], $expected['nid'], 'Same node');
        $actual = $values[$code];
        $this->assertTrue($actual['public_use'], 'Public use field set');
        foreach ($this->fields as $name) {
          $message = "The '$name' field matches in the '$code' summary";
          $this->assertEqual($actual[$name], $expected[$name], $message);
        }
      }
      else {
        $this->assertArrayNotHasKey($code, $values, "No '$code' translation");
      }
    }
  }

  /**
   * Release the summaries to the web site.
   */
  private function publish() {
    $summaries = [
      [$this->english['nid'], 'en'],
      [$this->spanish['nid'], 'es'],
    ];
    $response = $this->request('POST', $this->pdqUrl, ['json' => $summaries]);
    $this->assertEqual($response->getStatusCode(), 200);
    $errors = json_decode($response->getBody()->__toString(), TRUE)['errors'];
    $this->assertEmpty($errors);
  }

  /**
   * Delete a summary (or try to and fail).
   *
   * @param array $summary
   *   Values for the summary to be deleted.
   * @param bool $success_expected
   *   TRUE if the deletion should succeed.
   */
  private function delete(array $summary, $success_expected) {
    $cdr_id = $summary['cdr_id'];
    $response = $this->request('DELETE', "$this->pdqUrl/$cdr_id");
    if ($success_expected) {
      $this->assertEqual($response->getStatusCode(), 204);
      $response = $this->request('GET', "$this->pdqUrl/$cdr_id");
      $this->assertEqual($response->getStatusCode(), 404);
    }
    else {
      $nodes = $this->findNodes($cdr_id);
      $this->assertEqual($nodes, [[$summary['nid'], $summary['language']]]);
      $this->assertEqual($response->getStatusCode(), 400);
      $payload = json_decode($response->getBody()->__toString(), TRUE);
      $this->assertEqual($payload['message'], 'Spanish translation exists');
    }
  }

}
