<?php

/**
 * @file tests/classes/monograph/SubmissionFileDAOTest.inc.php
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2000-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SubmissionFileDAOTest
 * @ingroup tests_classes_monograph
 * @see SubmissionFileDAO
 *
 * @brief Test class for SubmissionFileDAO.
 */

import('lib.pkp.tests.DatabaseTestCase');
import('classes.monograph.SubmissionFileDAO');
import('classes.monograph.ArtworkFileDAODelegate');
import('classes.monograph.MonographFile');
import('classes.monograph.ArtworkFile');
import('classes.monograph.MonographDAO');
import('classes.monograph.Genre');
import('classes.monograph.reviewRound.ReviewRound');
import('lib.pkp.classes.db.DBResultRange');

// Define test ids.
define('SUBMISSION_FILE_DAO_TEST_PRESS_ID', 999);
define('SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID', 9999);
define('SUBMISSION_FILE_DAO_TEST_DOC_GENRE_ID', 1);
define('SUBMISSION_FILE_DAO_TEST_ART_GENRE_ID', 2);


// Define a temp file location for testing.
define('TMP_FILES', '/tmp');

class SubmissionFileDAOTest extends DatabaseTestCase {
	private $testFile;

	protected function setUp() {
		// Create a test file on the file system.
		$this->testFile = tempnam(TMP_FILES, 'SubmissionFile');

		// Register a mock monograph DAO.
		$monographDao = $this->getMock('MonographDAO', array('getMonograph'));
		$monograph = new Monograph();
		$monograph->setId(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID);
		$monograph->setPressId(SUBMISSION_FILE_DAO_TEST_PRESS_ID);
		$monographDao->expects($this->any())
		             ->method('getMonograph')
		             ->will($this->returnValue($monograph));
		DAORegistry::registerDAO('MonographDAO', $monographDao);

		// Register a mock genre DAO.
		$genreDao = $this->getMock('GenreDAO', array('getById'));
		DAORegistry::registerDAO('GenreDAO', $genreDao);
		$genreDao->expects($this->any())
		         ->method('getById')
		         ->will($this->returnCallback(array($this, 'getTestGenre')));

		$this->_cleanFiles();
	}

	protected function tearDown() {
		if (file_exists($this->testFile)) unlink($this->testFile);
		$this->_cleanFiles();
	}

	/**
	 * @covers SubmissionFileDAO
	 * @covers PKPSubmissionFileDAO
	 * @covers MonographFileDAODelegate
	 * @covers ArtworkFileDAODelegate
	 * @covers SubmissionFileDAODelegate
	 */
	public function testSubmissionFileCrud() {
		//
		// Create test data.
		//
		// Create two test files, one monograph file one artwork file.
		$file1Rev1 = new ArtworkFile();
		$file1Rev1->setName('test-artwork', 'en_US');
		$file1Rev1->setCaption('test-caption');
		$file1Rev1->setFileStage(SUBMISSION_FILE_PROOF);
		$file1Rev1->setSubmissionId(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID);
		$file1Rev1->setFileType('image/jpeg');
		$file1Rev1->setFileSize(512);
		$file1Rev1->setDateUploaded('2011-12-04 00:00:00');
		$file1Rev1->setDateModified('2011-12-04 00:00:00');
		$file1Rev1->setAssocType(ASSOC_TYPE_REVIEW_ASSIGNMENT);
		$file1Rev1->setAssocId(5);

		$file2Rev1 = new MonographFile();
		$file2Rev1->setName('test-document', 'en_US');
		$file2Rev1->setFileStage(SUBMISSION_FILE_PROOF);
		$file2Rev1->setSubmissionId(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID);
		$file2Rev1->setFileType('application/pdf');
		$file2Rev1->setFileSize(256);
		$file2Rev1->setDateUploaded('2011-12-05 00:00:00');
		$file2Rev1->setDateModified('2011-12-05 00:00:00');


		//
		// isInlineable()
		//
		// Test the isInlineable method.
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao SubmissionFileDAO */
		self::assertFalse($submissionFileDao->isInlineable($file2Rev1));
		self::assertTrue($submissionFileDao->isInlineable($file1Rev1));


		//
		// insertObject()
		//
		// Persist the two test files.
		$this->_insertFile($file1Rev1, 'test artwork', SUBMISSION_FILE_DAO_TEST_ART_GENRE_ID);
		self::assertType('ArtworkFile', $file1Rev1);
		$this->_insertFile($file2Rev1, 'test monograph', SUBMISSION_FILE_DAO_TEST_DOC_GENRE_ID);
		self::assertType('MonographFile', $file2Rev1);

		// Persist a second revision of the artwork file but this time with a
		// document genre so that it needs to be downcast for insert.
		$downcastFile = clone($file1Rev1); /* @var $downcastFile ArtworkFile */
		$downcastFile->setRevision(2);
		$downcastFile->setDateUploaded('2011-12-05 00:00:00');
		$downcastFile->setDateModified('2011-12-05 00:00:00');
		$file1Rev2 =& $this->_insertFile($downcastFile, 'test downcast', SUBMISSION_FILE_DAO_TEST_DOC_GENRE_ID);

		// Test whether the target type is correct.
		self::assertType('MonographFile', $file1Rev2);
		// Test that no data on the target interface has been lost.
		$this->_compareFiles($downcastFile, $file1Rev2);

		// Persist a second revision of the monograph file but this time with an
		// artwork genre so that it needs to be upcast for insert.
		$upcastFile = clone($file2Rev1); /* @var $upcastFile MonographFile */
		$upcastFile->setRevision(2);
		$file2Rev2 =& $this->_insertFile($upcastFile, 'test upcast', SUBMISSION_FILE_DAO_TEST_ART_GENRE_ID);

		// Test whether the target type is correct.
		self::assertType('ArtworkFile', $file2Rev2);
		// Test that no data on the target interface has been lost.
		$this->_compareFiles($upcastFile, $file2Rev2);
		// Make sure that other fields contain default values as
		// they are empty on upcast.
		self::assertNull($file2Rev2->getCaption());


		//
		// getRevision()
		//
		// Retrieve the first revision of the artwork file.
		self::assertNull($submissionFileDao->getRevision(null, $file1Rev1->getRevision()));
		self::assertNull($submissionFileDao->getRevision($file1Rev1->getFileId(), null));
		self::assertEquals($file1Rev1, $submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision()));
		self::assertEquals($file1Rev1, $submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision(), $file1Rev1->getFileStage()));
		self::assertEquals($file1Rev1, $submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision(), $file1Rev1->getFileStage(), SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID));
		self::assertNull($submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision(), SUBMISSION_FILE_PROOF+1));
		self::assertNull($submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision(), null, SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID+1));


		//
		// updateObject()
		//
		// Update the artwork file.
		$file1Rev1->setOriginalFileName('updated-file-name');
		$file1Rev1->setCaption('test-caption');
		$updatedFile =& $submissionFileDao->updateObject($file1Rev1);

		// Now change the genre so that the canonical file name
		// and the file implementation will have to change.
		$previousFilePath = $file1Rev1->getFilePath();
		$file1Rev1->setGenreId(SUBMISSION_FILE_DAO_TEST_DOC_GENRE_ID);
		$updatedFile =& $submissionFileDao->updateObject($file1Rev1);

		// Test whether the target type is correct.
		self::assertType('MonographFile', $updatedFile);
		// Test that no data on the target interface has been lost.
		$this->_compareFiles($file1Rev1, $updatedFile);

		// Test the new file path and files.
		$newFilePath = $updatedFile->getFilePath();
		self::assertNotEquals($previousFilePath, $newFilePath);
		self::assertFileNotExists($previousFilePath);
		self::assertFileExists($newFilePath);

		// Now change the genre back so that we can test casting
		// in the other direction.
		$updatedFile->setGenreId(SUBMISSION_FILE_DAO_TEST_ART_GENRE_ID);
		$updatedFile =& $submissionFileDao->updateObject($updatedFile);

		// Test whether the target type is correct.
		self::assertType('ArtworkFile', $updatedFile);
		// Test that no data on the target interface has been lost.
		$this->_compareFiles($file1Rev1, $updatedFile);
		// Make sure that other fields contain default values as
		// they are lost on double recast.
		self::assertNull($updatedFile->getCaption());
		$file1Rev1 = $updatedFile;


		//
		// getLatestRevision()
		//
		// Retrieve the latest revision of file 1.
		self::assertNull($submissionFileDao->getLatestRevision(null));
		self::assertEquals($file1Rev2, $submissionFileDao->getLatestRevision($file1Rev1->getFileId()));
		self::assertEquals($file1Rev2, $submissionFileDao->getLatestRevision($file1Rev1->getFileId(), $file1Rev1->getFileStage()));
		self::assertEquals($file1Rev2, $submissionFileDao->getLatestRevision($file1Rev1->getFileId(), $file1Rev1->getFileStage(), SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID));
		self::assertNull($submissionFileDao->getLatestRevision($file1Rev1->getFileId(), SUBMISSION_FILE_PROOF+1));
		self::assertNull($submissionFileDao->getLatestRevision($file1Rev1->getFileId(), null, SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID+1));


		//
		// getLatestRevisions()
		//
		// Calculate the unique ids of the test files.
		$uniqueId1_1 = $file1Rev1->getFileIdAndRevision();
		$uniqueId1_2 = $file1Rev2->getFileIdAndRevision();
		$uniqueId2_1 = $file2Rev1->getFileIdAndRevision();
		$uniqueId2_2 = $file2Rev2->getFileIdAndRevision();

		// Retrieve the latest revisions of both files.
		self::assertNull($submissionFileDao->getLatestRevisions(null));
		self::assertEquals(array($uniqueId1_2 => $file1Rev2, $uniqueId2_2 => $file2Rev2),
				$submissionFileDao->getLatestRevisions(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID));
		self::assertEquals(array($uniqueId1_2 => $file1Rev2, $uniqueId2_2 => $file2Rev2),
				$submissionFileDao->getLatestRevisions(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, SUBMISSION_FILE_PROOF));
		self::assertEquals(array(),
				$submissionFileDao->getLatestRevisions(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID+1));
		self::assertEquals(array(),
				$submissionFileDao->getLatestRevisions(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, SUBMISSION_FILE_PROOF+1));

		// Test paging.
		$rangeInfo = new DBResultRange(2, 1);
		self::assertEquals(array($uniqueId1_2 => $file1Rev2, $uniqueId2_2 => $file2Rev2),
				$submissionFileDao->getLatestRevisions(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, null, $rangeInfo));
		$rangeInfo = new DBResultRange(1, 1);
		self::assertEquals(array($uniqueId1_2 => $file1Rev2),
				$submissionFileDao->getLatestRevisions(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, null, $rangeInfo));
		$rangeInfo = new DBResultRange(1, 2);
		self::assertEquals(array($uniqueId2_2 => $file2Rev2),
				$submissionFileDao->getLatestRevisions(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, null, $rangeInfo));


		//
		// getAllRevisions()
		//
		// Retrieve all revisions of file 1.
		self::assertNull($submissionFileDao->getAllRevisions(null));
		self::assertEquals(array($uniqueId1_2 => $file1Rev2, $uniqueId1_1 => $file1Rev1),
				$submissionFileDao->getAllRevisions($file1Rev1->getFileId(), SUBMISSION_FILE_PROOF));
		self::assertEquals(array($uniqueId1_2 => $file1Rev2, $uniqueId1_1 => $file1Rev1),
				$submissionFileDao->getAllRevisions($file1Rev1->getFileId(), SUBMISSION_FILE_PROOF, SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID));
		self::assertEquals(array(),
				$submissionFileDao->getAllRevisions($file1Rev1->getFileId(), null, SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID+1));
		self::assertEquals(array(),
				$submissionFileDao->getAllRevisions($file1Rev1->getFileId(), SUBMISSION_FILE_PROOF+1, null));


		//
		// getLatestRevisionsByAssocId()
		//
		// Retrieve the latest revisions by association.
		self::assertNull($submissionFileDao->getLatestRevisionsByAssocId(ASSOC_TYPE_REVIEW_ASSIGNMENT, null));
		self::assertNull($submissionFileDao->getLatestRevisionsByAssocId(null, 5));
		self::assertEquals(array($uniqueId1_2 => $file1Rev2),
				$submissionFileDao->getLatestRevisionsByAssocId(ASSOC_TYPE_REVIEW_ASSIGNMENT, 5));
		self::assertEquals(array($uniqueId1_2 => $file1Rev2),
				$submissionFileDao->getLatestRevisionsByAssocId(ASSOC_TYPE_REVIEW_ASSIGNMENT, 5, SUBMISSION_FILE_PROOF));
		self::assertEquals(array(),
				$submissionFileDao->getLatestRevisionsByAssocId(ASSOC_TYPE_REVIEW_ASSIGNMENT, 5, SUBMISSION_FILE_PROOF+1));

		// Retrieve all revisions by association.
		self::assertNull($submissionFileDao->getAllRevisionsByAssocId(ASSOC_TYPE_REVIEW_ASSIGNMENT, null));
		self::assertNull($submissionFileDao->getAllRevisionsByAssocId(null, 5));
		self::assertEquals(array($uniqueId1_2 => $file1Rev2, $uniqueId1_1 => $file1Rev1),
				$submissionFileDao->getAllRevisionsByAssocId(ASSOC_TYPE_REVIEW_ASSIGNMENT, 5));
		self::assertEquals(array($uniqueId1_2 => $file1Rev2, $uniqueId1_1 => $file1Rev1),
				$submissionFileDao->getAllRevisionsByAssocId(ASSOC_TYPE_REVIEW_ASSIGNMENT, 5, SUBMISSION_FILE_PROOF));
		self::assertEquals(array(), $submissionFileDao->getAllRevisionsByAssocId(ASSOC_TYPE_REVIEW_ASSIGNMENT, 5, SUBMISSION_FILE_PROOF+1));


		//
		// assignRevisionToReviewRound()
		//
		// Insert one more revision to test review round file assignments.
		$file1Rev3 = clone($file1Rev2);
		$file1Rev3->setRevision(3);
		self::assertEquals($file1Rev3, $submissionFileDao->insertObject($file1Rev3, $this->testFile));
		$uniqueId1_3 = $file1Rev3->getFileIdAndRevision();

		// Insert review round file assignments.
		$submissionFileDao->assignRevisionToReviewRound($file1Rev1->getFileId(), $file1Rev1->getRevision(),
				WORKFLOW_STAGE_ID_INTERNAL_REVIEW, 1, SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID);
		$submissionFileDao->assignRevisionToReviewRound($file2Rev2->getFileId(), $file2Rev2->getRevision(),
				WORKFLOW_STAGE_ID_INTERNAL_REVIEW, 1, SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID);


		//
		// getRevisionsByReviewRound()
		//
		// Retrieve assigned review round files by review stage id and round.
		self::assertEquals(array($uniqueId1_1 => $file1Rev1, $uniqueId2_2 => $file2Rev2),
				$submissionFileDao->getRevisionsByReviewRound(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, WORKFLOW_STAGE_ID_INTERNAL_REVIEW, 1));
		self::assertNull($submissionFileDao->getRevisionsByReviewRound(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, null, null));


		//
		// getLatestNewRevisionsByReviewRound()
		//
		// Retrieve revisions of review round files that are newer than the review round files themselves.
		self::assertEquals(array($uniqueId1_3 => $file1Rev3),
				$submissionFileDao->getLatestNewRevisionsByReviewRound(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, WORKFLOW_STAGE_ID_INTERNAL_REVIEW, 1));
		self::assertNull($submissionFileDao->getLatestNewRevisionsByReviewRound(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, null, null));


		//
		// deleteAllRevisionsByReviewRound()
		//
		$submissionFileDao->deleteAllRevisionsByReviewRound(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, WORKFLOW_STAGE_ID_INTERNAL_REVIEW, 1);
		self::assertEquals(array(),
				$submissionFileDao->getRevisionsByReviewRound(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID, WORKFLOW_STAGE_ID_INTERNAL_REVIEW, 1));


		//
		// deleteRevision() and deleteRevisionById()
		//
		// Delete the first revision of file1.
		// NB: This implicitly tests deletion by ID.
		self::assertEquals(1, $submissionFileDao->deleteRevision($file1Rev1));
		self::assertNull($submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision()));
		// Re-insert the file for the next test.
		self::assertEquals($file1Rev1, $submissionFileDao->insertObject($file1Rev1, $this->testFile));


		//
		// deleteLatestRevisionById()
		//
		// Delete the latest revision of file1.
		self::assertEquals(1, $submissionFileDao->deleteLatestRevisionById($file1Rev1->getFileId()));
		self::assertType('ArtworkFile', $submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision()));
		self::assertNull($submissionFileDao->getRevision($file1Rev3->getFileId(), $file1Rev3->getRevision()));


		//
		// deleteAllRevisionsById()
		//
		// Delete all revisions of file1.
		self::assertEquals(2, $submissionFileDao->deleteAllRevisionsById($file1Rev1->getFileId()));
		self::assertType('MonographFile', $submissionFileDao->getRevision($file2Rev1->getFileId(), $file2Rev1->getRevision()));
		self::assertNull($submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision()));
		self::assertNull($submissionFileDao->getRevision($file1Rev2->getFileId(), $file1Rev2->getRevision()));
		// Re-insert the files for the next test.
		self::assertEquals($file1Rev1, $submissionFileDao->insertObject($file1Rev1, $this->testFile));
		self::assertEquals($file1Rev2, $submissionFileDao->insertObject($file1Rev2, $this->testFile));


		//
		// deleteAllRevisionsByAssocId()
		//
		// Delete all revisions by assoc id.
		self::assertEquals(2, $submissionFileDao->deleteAllRevisionsByAssocId(ASSOC_TYPE_REVIEW_ASSIGNMENT, 5));
		self::assertType('MonographFile', $submissionFileDao->getRevision($file2Rev1->getFileId(), $file2Rev1->getRevision()));
		self::assertNull($submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision()));
		self::assertNull($submissionFileDao->getRevision($file1Rev2->getFileId(), $file1Rev2->getRevision()));
		// Re-insert the files for the next test.
		self::assertEquals($file1Rev1, $submissionFileDao->insertObject($file1Rev1, $this->testFile));
		self::assertEquals($file1Rev2, $submissionFileDao->insertObject($file1Rev2, $this->testFile));


		//
		// deleteAllRevisionsBySubmissionId()
		//
		// Delete all revisions by submission id.
		self::assertEquals(4, $submissionFileDao->deleteAllRevisionsBySubmissionId(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID));
		self::assertNull($submissionFileDao->getRevision($file2Rev1->getFileId(), $file2Rev1->getRevision()));
		self::assertNull($submissionFileDao->getRevision($file1Rev1->getFileId(), $file1Rev1->getRevision()));
		self::assertNull($submissionFileDao->getRevision($file1Rev2->getFileId(), $file1Rev2->getRevision()));


		//
		// insertObject() for new revisions
		//
		// Test insertion of new revisions.
		// Create two files with different file ids.
		$file1Rev1->setFileId(null);
		$file1Rev1->setRevision(null);
		$file1Rev1 =& $submissionFileDao->insertObject($file1Rev1, $this->testFile);
		$file1Rev2->setFileId(null);
		$file1Rev2->setRevision(null);
		$file1Rev2->setGenreId(SUBMISSION_FILE_DAO_TEST_DOC_GENRE_ID);
		$file1Rev2 =& $submissionFileDao->insertObject($file1Rev2, $this->testFile);

		// Test the file ids, revisions and identifying fields.
		self::assertNotEquals($file1Rev1->getFileId(), $file1Rev2->getFileId());
		self::assertNotEquals($file1Rev1->getGenreId(), $file1Rev2->getGenreId());
		self::assertEquals(1, $submissionFileDao->getLatestRevisionNumber($file1Rev1->getFileId()));
		self::assertEquals(1, $submissionFileDao->getLatestRevisionNumber($file1Rev2->getFileId()));


		//
		// setAsLatestRevision()
		//
		// Now make the second file a revision of the first.
		$file1Rev2 =& $submissionFileDao->setAsLatestRevision($file1Rev1->getFileId(), $file1Rev2->getFileId(),
				$file1Rev1->getSubmissionId(), $file1Rev1->getFileStage());

		// And test the file ids, revisions, identifying fields and types again.
		self::assertEquals($file1Rev1->getFileId(), $file1Rev2->getFileId());
		self::assertEquals($file1Rev1->getGenreId(), $file1Rev2->getGenreId());
		self::assertEquals(1, $file1Rev1->getRevision());
		self::assertEquals(2, $submissionFileDao->getLatestRevisionNumber($file1Rev1->getFileId()));
		$submissionFiles =& $submissionFileDao->getAllRevisions($file1Rev1->getFileId());
		self::assertEquals(2, count($submissionFiles));
		foreach($submissionFiles as $submissionFile) { /* @var $submissionFile SubmissionFile */
			self::assertType('ArtworkFile', $submissionFile);
		}
	}

	function testNewDataObjectByGenreId() {
		// Instantiate the SUT.
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao SubmissionFileDAO */

		// Test whether the newDataObjectByGenreId method will return a monograph file.
		$fileObject = $submissionFileDao->newDataObjectByGenreId(SUBMISSION_FILE_DAO_TEST_DOC_GENRE_ID);
		self::assertType('MonographFile', $fileObject);

		// Now set an artwork genre and try again.
		$fileObject = $submissionFileDao->newDataObjectByGenreId(SUBMISSION_FILE_DAO_TEST_ART_GENRE_ID);
		self::assertType('ArtworkFile', $fileObject);
	}


	//
	// Public helper methods
	//
	/**
	 * Return a test genre.
	 * @param $genreId integer
	 * @return Genre the test genre.
	 */
	public function getTestGenre($genreId) {
		// Create a test genre.
		switch($genreId) {
			case SUBMISSION_FILE_DAO_TEST_DOC_GENRE_ID:
				$category = GENRE_CATEGORY_DOCUMENT;
				$name = 'Document Genre';
				$designation = 'D';
				break;

			case SUBMISSION_FILE_DAO_TEST_ART_GENRE_ID:
				$category = GENRE_CATEGORY_ARTWORK;
				$name = 'Artwork Genre';
				$designation = 'A';
				break;

			default:
				self::fail();
		}
		$genre = new Genre();
		$press = Request::getPress();
		$genre->setPressId($press->getId());
		$genre->setId($genreId);
		$genre->setName($name, 'en_US');
		$genre->setDesignation($designation);
		$genre->setCategory($category);
		return $genre;
	}


	//
	// Private helper methods
	//
	/**
	 * Compare the common properties of monograph and
	 * artwork files even when the two files do not have the
	 * same implementation.
	 * @param $sourceFile MonographFile
	 * @param $targetFile MonographFile
	 */
	function _compareFiles($sourceFile, $targetFile) {
		self::assertEquals($sourceFile->getName('en_US'), $targetFile->getName('en_US'));
		self::assertEquals($sourceFile->getFileStage(), $targetFile->getFileStage());
		self::assertEquals($sourceFile->getSubmissionId(), $targetFile->getSubmissionId());
		self::assertEquals($sourceFile->getFileType(), $targetFile->getFileType());
		self::assertEquals($sourceFile->getFileSize(), $targetFile->getFileSize());
		self::assertEquals($sourceFile->getDateUploaded(), $targetFile->getDateUploaded());
		self::assertEquals($sourceFile->getDateModified(), $targetFile->getDateModified());
		self::assertEquals($sourceFile->getAssocType(), $targetFile->getAssocType());
		self::assertEquals($sourceFile->getAssocId(), $targetFile->getAssocId());
	}

	/**
	 * Prepare and test inserting a file
	 * @param $file SubmissionFile
	 * @param $testContent string
	 * @param $genreCategory integer
	 * @return SubmissionFile
	 */
	private function _insertFile($file, $testContent, $genreId) {
		// Prepare the test.
		file_put_contents($this->testFile, $testContent);
		$file->setGenreId($genreId);

		// Insert the file.
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao SubmissionFileDAO */
		$file =& $submissionFileDao->insertObject($file, $this->testFile);

		// Test the outcome.
		self::assertFileExists($file->getFilePath());
		self::assertEquals($testContent, file_get_contents($file->getFilePath()));

		return $file;
	}

	/**
	 * Remove remnants from the tests.
	 */
	private function _cleanFiles() {
		// Delete the test submission's files.
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao SubmissionFileDAO */
		$submissionFileDao->deleteAllRevisionsBySubmissionId(SUBMISSION_FILE_DAO_TEST_SUBMISSION_ID);
	}
}
?>
