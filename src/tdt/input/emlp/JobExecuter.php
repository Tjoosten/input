<?php

namespace tdt\input\emlp;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * The job class can kickstart the emlp sequence assembled by
 *      * the extractor
 *      * the mapper (can be null)
 *      * the loader
 *      * the publisher (can be null)
 * @license AGPLv3
 * @author Jan Vansteenlandt <jan@okfn.be>
 * @author Pieter Colpaert <pieter@okfn.be>
 */
class JobExecuter{

    private $job;

    /**
     * Create a new job with emlp relations
     */
    public function __construct($job){
        $this->job = $job;
    }

    /**
     * Execute the job
     */
    public function execute(){

        // Fetch the extractor, mapper (optional), loader and publisher (optional)
        // and use as constructor variables for the empl wrappers
        $extractor_model = $this->job->extractor()->first();
        $extractor = $this->getExecuter($extractor_model);

        $mapper_model = null;
        if(!empty($this->job->mapper_type)){

            $mapper_model = $this->job->mapper()->first();
            $mapper = $this->getExecuter($mapper_model);
        }

        $loader_model = $this->job->loader()->first();
        $loader = $this->getExecuter($loader_model);

        $publisher_model = null;
        if(!empty($this->job->publisher_type)){

            $publisher_model = $this->job->publisher()->first();
            $publisher = $this->getExecuter($publisher_model);
        }

        // Register the start of the execution
        $start = microtime(true);

        // Keep track of the number of chunks that were processed
        $numberofchunks = 0;

        // Create the job id
        $id = $this->job->collection_uri . '/' . $this->job->name;
        $timestamp = date('d-m-Y H:i:s');

        // Log the start of the entire emlp execution
        $this->log("Started executing the job identified by $id at $timestamp.");

        // While the extractor reads chunks, keep executing the eml sequence
        $count_triples = 0;
        $count_chunks = 0;

        while ($extractor->hasNext()) {

            $chunk = $extractor->pop();

            if(!empty($chunk)){

                $count_chunks++;

                // Perform the mapping if present
                if(!empty($mapper)) {

                    $chunk = $mapper->execute($chunk);
                }

                // Perform the loader processing
                $loader->execute($chunk);

                // Cumulate the amount of triples
                if(!empty($chunk)){
                    $count_triples += $chunk->countTriples();
                }
            }else{
                $this->log("Empty chunk retrieved from the extractor, previous chunk count was $count_chunks.");

            }
        }

        // Clean up after loader execution
        $loader->cleanUp();

        $duration = round(microtime(true) - $start, 2);

        $this->log("Extracted a total of $count_chunks chunks from the source file, loaded a total of $count_triples triples  in " . $duration . " seconds.");

        // Execute the publisher if present ( optional )
        if(!empty($publisher)){
            $publisher->execute();
        }

        $timestamp = date('d-m-Y H:i:s');
        $this->log("Ended job execution at $timestamp.");
    }

    /**
     * Retrieve the executer for a certain model of the emlp
     * $model will be any existing empl model
     *
     * example $model -> class is extract\Csv
     * @return new emlp\extract\Csv($model)
     */
    private function getExecuter($model){

        if(empty($model)){
            return $model;
        }

        $executer = 'tdt\\input\\emlp\\' . get_class($model);

        if(!class_exists($executer)){

            $model_class = get_class($model);
            // This error shouldn't occur when validation has returned true
            // If this fails, it means we did something wrong
            \App::abort(500, "The executer ($executer) was not found for the corresponding model $model_class).");
        }

        $executer = new $executer($model);

        return $executer;
    }

    /**
     * Log something to the output
     */
    protected function log($message){

        $class = explode('\\', get_called_class());
        $class = end($class);

        echo "JobExecuter: " . $message . "\n";
    }
}