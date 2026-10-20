describe('Playback', () => {
  it('has the audio file of every track in storage, with its length', () => {
    cy.tracks().each((t) => {
      expect(t.meta.duration, `${t.title.rendered} duration`).to.be.greaterThan(0)
      if (t.meta.stream.includes('amazonaws.com')) cy.s3Status(t.meta.stream).should('be.oneOf', [200, 206])
    })
  })

  it('plays a track from its page', () => {
    cy.tracks().then((tracks) => {
      cy.visit(tracks.find((t) => t.meta.stream.includes('amazonaws.com')).link)
      cy.get('.entry-header .btn-play, article .btn-play, main .btn-play').first().click({ force: true })
      cy.get('audio, video').should(($m) => expect($m.toArray().some((m) => !m.paused && !m.error)).to.equal(true))
    })
  })

  it('shows the radio player', () => {
    cy.visit('/radio/')
    cy.get('iframe.empyrian-radio-frame').should('have.attr', 'src').and('include', 'empyrian_radio=embed')
  })
})
