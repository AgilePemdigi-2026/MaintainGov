@http
Feature: Sprint 1 through the real HTTP API
  HTTP checks use cookie sessions, a queue, the configured model and a persistent database.
  Browser steps in the original feature files remain a separate UI review.

  Scenario: Maintenance member authenticates
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    Then my API role is "Tim Maintenance"

  Scenario: Developer authenticates
    Given a fresh unauthenticated API session
    When I log in as "developer"
    Then my API role is "Developer"

  Scenario: Invalid credentials are rejected
    Given a fresh unauthenticated API session
    When I log in with an invalid password
    Then the HTTP response is 401
    And the API does not identify which credential was wrong

  Scenario: Logout rejects the old cookie
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    And I log out and replay my old cookie
    Then the HTTP response is 401

  Scenario: Feedback creates persisted Pending cases and replay is idempotent
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    And I submit the valid feedback fixture
    Then the batch eventually becomes "Selesai"
    And Pending cases are linked to their source reviews
    When I request the same batch again and replay the upload
    Then the stored review and case identifiers are unchanged

  Scenario: Invalid CSV can be replaced by valid CSV
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    And I submit a CSV without the required columns
    Then the HTTP response is 422
    And the response explains the CSV structure problem
    When I submit the valid feedback fixture
    Then the batch eventually becomes "Selesai"

  Scenario: Developer cannot start analysis
    Given a fresh unauthenticated API session
    When I log in as "developer"
    And I submit the valid feedback fixture
    Then the HTTP response is 403

  Scenario: Reviews beyond item 200 remain accessible
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    And I submit a fixture with 201 reviews
    Then the batch eventually becomes "Selesai"
    And review 201 is accessible on a later page

  @service_down
  Scenario: Unavailable analysis service produces no partial cases
    Given a fresh unauthenticated API session
    And the configured analysis service is unavailable
    When I log in as "maintenance"
    And I submit the valid feedback fixture
    Then the batch eventually becomes "Gagal"
    And no partial analysis output is stored
