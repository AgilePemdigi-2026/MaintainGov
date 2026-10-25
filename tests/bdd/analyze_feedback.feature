Feature: Analyze user feedback
  Sebagai anggota Tim Maintenance,
  saya ingin mengunggah CSV feedback pengguna dan mendapatkan hasil analisis,
  agar saya dapat mengelola maintenance case berdasarkan feedback nyata.

  Scenario: Valid feedback produces persisted pending cases
    Given I am authenticated as a Maintenance Team member
    And a reviewed CSV fixture contains actionable feedback
    When I upload the CSV and start analysis
    Then the analysis batch eventually has status "Selesai"
    And the generated Maintenance Cases have status "Pending"
    And each case is linked to its source reviews
    When I reload the result page
    Then the persisted results are still displayed

  Scenario: Invalid CSV is rejected before analysis
    Given I am authenticated as a Maintenance Team member
    When I upload a file with missing required columns
    Then I receive error "Struktur CSV tidak sesuai" with a list of required columns
    And no analysis batch is created
    And the upload button becomes active again after selecting a valid file

  Scenario: Analysis service failure marks batch as Gagal
    Given I am authenticated as a Maintenance Team member
    And the analysis service is unavailable
    When I upload a valid CSV and start analysis
    Then the analysis batch eventually has status "Gagal"
    And no partial cases are created
    And the error is recorded in the batch metadata

  Scenario: Service timeout does not produce false success
    Given I am authenticated as a Maintenance Team member
    And the analysis service exceeds the configured timeout
    When I upload a valid CSV and start analysis
    Then the analysis batch eventually has status "Gagal"
    And no cases or partial results are persisted

  Scenario: Invalid service response never marks batch as successful
    Given I am authenticated as a Maintenance Team member
    And the analysis service returns mismatched review IDs
    When I upload a valid CSV and start analysis
    Then the analysis batch eventually has status "Gagal"
    And no cases are created
    And review analysis_result remains null

  Scenario: Retry of failed batch succeeds without duplicate cases
    Given I am authenticated as a Maintenance Team member
    And a previous analysis batch has status "Gagal"
    When I retry the failed batch and the service is now available
    Then the analysis batch eventually has status "Selesai"
    And there is exactly one Maintenance Case per group_key
    And no duplicate reviews or cases exist
    And a second retry of the now-successful batch is rejected with 409

  Scenario: Duplicate upload with same file returns existing batch
    Given I am authenticated as a Maintenance Team member
    And I have already uploaded a CSV file
    When I upload the same file again with the same idempotency key
    Then I receive the existing batch_id without creating a new batch
    And only one job exists in the queue

  Scenario: Stale worker from earlier attempt cannot overwrite newer attempt
    Given I am authenticated as a Maintenance Team member
    And an analysis batch is on attempt 2
    When a stale worker from attempt 1 tries to commit results
    Then the commit is silently discarded
    And the batch remains in its current state for attempt 2

  Scenario: Recovery marks only genuinely expired batches as failed
    Given analysis batches exist in various states:
      | batch_id | status   | started_at          | attempt |
      | 1        | Diproses | 10 minutes ago      | 1       |
      | 2        | Diproses | null                | 1       |
      | 3        | Diproses | 1 minute ago        | 1       |
      | 4        | Selesai  | 30 minutes ago      | 1       |
      | 5        | Gagal    | 30 minutes ago      | 1       |
    When the recovery command runs with timeout 300 seconds
    Then batch 1 is marked "Gagal" with a timeout error message
    And batch 2 remains "Diproses" (still queued, not started)
    And batch 3 remains "Diproses" (recently started, within timeout)
    And batch 4 remains "Selesai"
    And batch 5 remains "Gagal"
