<?php
/**
 * WP-CLI command for interacting with Solr Power
 *
 * @package Solr_Power
 */

/**
 * Perform a variety of actions against your Solr instance.
 */
class SolrPower_CLI extends WP_CLI_Command {

  /**
   * Check server settings.
   *
   * Pings the Solr server to see if the connection is functional.
   *
   * @subcommand check-server-settings
   */
  public function check_server_settings() {
    $retval = SolrPower_Api::get_instance()->ping_server();
    if ( $retval ) {
      WP_CLI::success( 'Server ping successful.' );
    } else {
      $last_error = SolrPower_Api::get_instance()->last_error;
      WP_CLI::error( "Server ping failed: {$last_error->getMessage()}" );
    }
  }

  /**
   * Remove one or more posts from the index.
   *
   * ## OPTIONS
   *
   * [<id>...]
   * : One or more post ids to remove from the index.
   *
   * [--all]
   * : Remove all posts from the index.
   *
   * ## EXAMPLES
   *
   *     $ wp solr delete 342
   *     Removed post 342 from the index.
   *     Success: Specified posts removed from the index.
   *
   *     $ wp solr delete --all
   *     Success: All posts successfully removed from the index.
   */
  public function delete( $args, $assoc_args ) {

    if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all' ) ) {
      if ( SolrPower_Sync::get_instance()->delete_all() ) {
        WP_CLI::success( 'All posts successfully removed from the index.' );
      } else {
        $last_error = SolrPower_Api::get_instance()->last_error;
        WP_CLI::error( "Couldn't remove all posts from the index: {$last_error->getMessage()}" );
      }
    } elseif ( count( $args ) ) {
      foreach ( $args as $post_id ) {
        if ( SolrPower_Sync::get_instance()->delete( absint( $post_id ) ) ) {
          WP_CLI::log( "Removed post {$post_id} from the index." );
        } else {
          $last_error = SolrPower_Api::get_instance()->last_error;
          WP_CLI::warning( "Couldn't removed post {$post_id} from the index: {$last_error->getMessage()}" );
        }
      }
      WP_CLI::success( 'Specified posts removed from the index.' );
    } else {
      WP_CLI::error( 'Please specify one or more post ids, or use the --all flag.' );
    }

  }

  /**
   * Index all posts for a site.
   *
   * [--batch=<batch>]
   * : Start indexing at a specific batch. Defaults to last indexed batch, or very beginning.
   *
   * [--post_type=<post-type>]
   * : Limit indexing to a specific post type. Defaults to all searchable.
   */
  public function index( $args, $assoc_args ) {

    $query_args = array();
    if ( isset( $assoc_args['post_type'] ) ) {
      $query_args['post_type'] = array( $assoc_args['post_type'] );
    }
    if ( isset( $assoc_args['batch'] ) ) {
      $query_args['batch'] = (int) $assoc_args['batch'];
    }

    $batch_index = new SolrPower_Batch_Index( $query_args );
    $displayed_batch = false;
    $start_time = microtime( true );
    while ( $batch_index->have_posts() ) {
      $current_batch = $batch_index->get_current_batch();
      if ( $current_batch !== $displayed_batch ) {
        WP_CLI::log( '' );
        $success_posts = $batch_index->get_success_posts();
        $failed_posts = $batch_index->get_failed_posts();
        $remaining_posts = $batch_index->get_remaining_posts();
        $total_batches = $batch_index->get_total_batches();
        $log_time = self::format_log_timestamp( microtime( true ) - $start_time );
        WP_CLI::log( "Starting batch {$current_batch} of {$total_batches} at {$log_time} elapsed time ({$success_posts} indexed, {$failed_posts} failed, {$remaining_posts} remaining)" );
        WP_CLI::log( '' );
        $displayed_batch = $current_batch;
      }
      $result = $batch_index->index_post();
      $post_mention = "'{$result['post_title']}' ({$result['post_id']})";
      if ( 'success' === $result['status'] ) {
        WP_CLI::log( "Submitted {$post_mention} to the index." );
      } elseif ( 'failed' === $result['status'] ) {
        WP_CLI::log( "Failed to index {$post_mention}: {$result['message']}" );
      }
      if ( ! $batch_index->have_posts() ) {
        $batch_index->fetch_next_posts();
      }
    }
    $success_posts = $batch_index->get_success_posts();
    $total_posts = $batch_index->get_total_posts();
    do_action( 'solr_power_index_all_finished' );
    WP_CLI::success( "Indexed {$success_posts} of {$total_posts} posts." );
  }

  /**
   * Report information about Solr Power configuration.
   *
   * Displays ping status, alongside Solr server IP address, port, and path.
   *
   * ## OPTIONS
   *
   * [--field=<field>]
   * : Display a specific field.
   *
   * [--format=<format>]
   * : Render output in a particular format.
   * ---
   * default: table
   * options:
   *   - table
   *   - json
   *   - yaml
   *   - csv
   * ---
   *
   * ## EXAMPLES
   *
   *     $ wp solr info
   *     +-------------+------------+
   *     | Field       | Value      |
   *     +-------------+------------+
   *     | ping_status | successful |
   *     | ip_address  | localhost  |
   *     | port        | 8983       |
   *     | path        | /solr      |
   *     +-------------+------------+
   *
   *     $ wp solr info --field=ip_address
   *     localhost
   */
  public function info( $args, $assoc_args ) {

    $server = SolrPower_Api::get_instance()->get_server_info();
    $server['ping_status'] = ( $server['ping_status'] ) ? 'successful' : 'failed';
    $formatter = new \WP_CLI\Formatter( $assoc_args, array( 'ping_status', 'ip_address', 'port', 'path' ) );
    $formatter->display_item( $server );
  }


  /**
   * Select one or more docs from the index based on a string.
   *
   * ## OPTIONS
   *
   * [<string>...]
   * : One or more strings to search in the index.
   *
   * [--all]
   * : Remove all posts from the index.
   *
   * ## EXAMPLES
   *
   *     $ wp solr select test
   *     Get documents with 'test' from index.
   *     Success: dump of document(s) from the index.
   *
   *     $ wp solr select --all
   *     Success: dump of document(s) from the index.
   */
  public function select( $args, $assoc_args ) {

    if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all' ) ) {
      $results = SolrPower_Sync::get_instance()->select_all();
      WP_CLI::success( 'finding all Posts' );
      if ( $results ) {
        $this->dump_result_set( $results );
      } else {
       $last_error = SolrPower_Api::get_instance()->last_error;
       WP_CLI::error( "Couldn't select all posts {$last_error->getMessage()}" );
      }
    } elseif ( count( $args ) ) {
        foreach ( $args as $query_str ) {
          $results = SolrPower_Sync::get_instance()->select(  $query_str  );
          WP_CLI::success(  'NumFound: '.$results->getNumFound() );
          if ( $results ) {
            $this->dump_result_set( $results );
          } else {
            $last_error = SolrPower_Api::get_instance()->last_error;
            WP_CLI::warning( "Couldn't select post {$query_str} from the index: {$last_error->getMessage()}" );
          }
        }

    } else {
      WP_CLI::error( 'Please specify one or more query strings, or use the --all flag.' );
    }

  }


  /**
   * Debug the query info for searching a string in the index.
   *
   * ## OPTIONS
   *
   * [<atring>...]
   * : One or more post strings to search in the index.
   *
   *
   * ## EXAMPLES
   *
   *     $ wp solr debug "test string"
   *     Success: Return debug explainer for query.

   */
  public function debug( $args, $assoc_args ) {

    if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all' ) ) {

       WP_CLI::error( "Can't debug all posts " );

    } elseif ( count( $args ) ) {
        foreach ( $args as $query_str ) {
          $result = SolrPower_Sync::get_instance()->debug(  $query_str  );

          if ( $result ) {
            $this->dump_debug( $result );
          } else {
            $last_error = SolrPower_Api::get_instance()->last_error;
            WP_CLI::warning( "Couldn't search text {$query_str} from the index: {$last_error->getMessage()}" );
          }
        }

    } else {
      WP_CLI::error( 'Please specify one or more post ids.' );
    }

  }




  /**
   * Optimize the Solr index.
   *
   * Calls Solarium's addOptimize() to 'defragment' your index. The space
   * taken by deleted document data is reclaimed and can merge the index into
   * fewer segments. This can improve search performance a lot.
   *
   * @subcommand optimize-index
   */
  public function optimize_index() {
    SolrPower_Api::get_instance()->optimize();
    WP_CLI::success( 'Index optimized.' );
  }

  /**
   * Repost schema.xml to Solr.
   *
   * Deletes the current index, then reposts the schema.xml to Solr.
   *
   * @subcommand repost-schema
   */
  public function repost_schema() {

    if ( ! getenv( 'PANTHEON_ENVIRONMENT' ) || ! getenv( 'FILEMOUNT' ) ) {
      WP_CLI::error( 'Schema reposting only works in a Pantheon environment.' );
    }

    SolrPower_Sync::get_instance()->delete_all();
    $output = SolrPower_Api::get_instance()->submit_schema();
    WP_CLI::success( "Schema reposted: {$output}" );
  }

  /**
   * Report stats about indexed content.
   *
   * Displays number of indexed posts for each enabled post type.
   *
   * ## OPTIONS
   *
   * [--field=<field>]
   * : Display a specific field.
   *
   * [--format=<format>]
   * : Render output in a particular format.
   * ---
   * default: table
   * options:
   *   - table
   *   - json
   *   - yaml
   *   - csv
   * ---
   *
   * ## EXAMPLES
   *
   *     $ wp solr stats
   *     +------------+-------+
   *     | Field      | Value |
   *     +------------+-------+
   *     | post       | 2     |
   *     | page       | 1     |
   *     | attachment | 0     |
   *     +------------+-------+
   *
   *     $ wp solr stats --field=page
   *     1
   */
  public function stats( $args, $assoc_args ) {
    $stats = SolrPower_Api::get_instance()->index_stats();
    $formatter = new \WP_CLI\Formatter( $assoc_args, array_keys( $stats ) );
    $formatter->display_item( $stats );
  }

  /**
   * Format a log timestamp into something human-readable.
   *
   * @param integer $s Log time in seconds.
   * @return string
   */
  private static function format_log_timestamp( $s ) {
    $h = floor( $s / 3600 );
    $s -= $h * 3600;
    $m = floor( $s / 60 );
    $s -= $m * 60;
    return $h . ':' . sprintf( '%02d', $m ) . ':' . sprintf( '%02d', $s );
  }


  /**
   * Format result documents into something human-readable.
   *
   * @param Result $results query result
   * @return null
   */
public function dump_result_set( $results )
  {
    foreach ($results as $document) {
        $output = [] ;

        // the documents are also iterable, to get all fields
        foreach ($document as $field => $value) {
            // this converts multivalue fields to a comma-separated string
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $output[$field] = $value ;
        }

        WP_CLI::log(  json_encode($output) );
    }
  }

public function dump_debug($debugResult)
{
        // display the debug results
    WP_CLI::log('<h1>Debug data</h1>');
    WP_CLI::log('Querystring: ' . $debugResult->getQueryString() . '<br/>');
    WP_CLI::log('Parsed query: ' . $debugResult->getParsedQuery() . '<br/>');
    WP_CLI::log('Query parser: ' . $debugResult->getQueryParser() . '<br/>');
    WP_CLI::log('Other query: ' . $debugResult->getOtherQuery() . '<br/>');

    WP_CLI::log('<h2>Explain data</h2>');
    foreach ($debugResult->getExplain() as $key => $explanation) {
        WP_CLI::log('<h3>Document key: ' . $key . '</h3>');
        WP_CLI::log('Value: ' . $explanation->getValue() . '<br/>');
        WP_CLI::log('Match: ' . (($explanation->getMatch() == true) ? 'true' : 'false')  . '<br/>');
        WP_CLI::log('Description: ' . $explanation->getDescription() . '<br/>');
        WP_CLI::log('<h4>Details</h4>');
        foreach ($explanation as $detail) {
            WP_CLI::log('Value: ' . $detail->getValue() . '<br/>');
            WP_CLI::log('Match: ' . (($detail->getMatch() == true) ? 'true' : 'false')  . '<br/>');
            WP_CLI::log('Description: ' . $detail->getDescription() . '<br/>');
            WP_CLI::log('<hr/>');
        }
    }

    WP_CLI::log('<h2>ExplainOther data</h2>');
    foreach ($debugResult->getExplainOther() as $key => $explanation) {
        WP_CLI::log('<h3>Document key: ' . $key . '</h3>');
        WP_CLI::log('Value: ' . $explanation->getValue() . '<br/>');
        WP_CLI::log('Match: ' . (($explanation->getMatch() == true) ? 'true' : 'false')  . '<br/>');
        WP_CLI::log('Description: ' . $explanation->getDescription() . '<br/>');
        WP_CLI::log('<h4>Details</h4>');
        foreach ($explanation as $detail) {
            WP_CLI::log('Value: ' . $detail->getValue() . '<br/>');
            WP_CLI::log('Match: ' . (($detail->getMatch() == true) ? 'true' : 'false')  . '<br/>');
            WP_CLI::log('Description: ' . $detail->getDescription() . '<br/>');
            WP_CLI::log('<hr/>');
        }
    }

    WP_CLI::log('<h2>Timings (in ms)</h2>');
    WP_CLI::log('Total time: ' . $debugResult->getTiming()->getTime() . '<br/>');
    WP_CLI::log('<h3>Phases</h3>');
    foreach ($debugResult->getTiming()->getPhases() as $phaseName => $phaseData) {
        WP_CLI::log('<h4>' . $phaseName . '</h4>');
        foreach ($phaseData as $class => $time) {
            WP_CLI::log($class . ': ' . $time . '<br/>');
        }
        WP_CLI::log('<hr/>');
    }
}


}
