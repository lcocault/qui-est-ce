-- Qui Est-Ce? – Database initialisation
-- PostgreSQL

CREATE TABLE IF NOT EXISTS parties (
    id                  SERIAL PRIMARY KEY,
    identifiant         VARCHAR(36)  NOT NULL UNIQUE,
    token_joueur1       VARCHAR(36)  NOT NULL UNIQUE,
    token_joueur2       VARCHAR(36)  NOT NULL UNIQUE,
    email_joueur1       VARCHAR(255) NOT NULL,
    email_joueur2       VARCHAR(255) NOT NULL,
    set_personnages     VARCHAR(100) NOT NULL DEFAULT 'Basic',
    personnage_joueur1  VARCHAR(100),
    personnage_joueur2  VARCHAR(100),
    -- JSON arrays of eliminated character names (from the player's OWN perspective)
    elimines_joueur1    TEXT         NOT NULL DEFAULT '[]',
    elimines_joueur2    TEXT         NOT NULL DEFAULT '[]',
    -- 1 = joueur 1 doit poser une question, 2 = joueur 2
    tour                INT          NOT NULL DEFAULT 1,
    question_en_cours   TEXT,
    question_posee_par  INT,
    -- choix_personnage | en_cours | terminee
    etat                VARCHAR(30)  NOT NULL DEFAULT 'choix_personnage',
    gagnant             INT,
    created_at          TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS questions (
    id          SERIAL PRIMARY KEY,
    partie_id   INT     NOT NULL REFERENCES parties(id) ON DELETE CASCADE,
    posee_par   INT     NOT NULL,
    question    TEXT    NOT NULL,
    reponse     BOOLEAN,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
