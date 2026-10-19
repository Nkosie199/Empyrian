describe('Content categories', () => {
  it('gives every track a genre and an artist', () => {
    cy.tracks().each((t) => {
      expect(t.genre, `${t.title.rendered} genre`).to.not.be.empty
      expect(t.artist, `${t.title.rendered} artist`).to.not.be.empty
    })
  })

  ;['kult', 'hip-hop', 'rap'].forEach((genre) => {
    it(`lists ${genre} tracks on the ${genre} genre page`, () => {
      cy.termId('genre', genre).then((id) => cy.restGet(`posts?genre=${id}&per_page=12&_fields=link`)).then((posts) => {
        expect(posts).to.not.be.empty
        cy.visit(`/genre/${genre}/`)
        posts.forEach((p) => cy.get(`a[href="${p.link}"]`).should('exist'))
      })
    })
  })

  it('lists only genres and artists that have music', () => {
    cy.visit('/genres/')
    cy.get('a[href*="/genre/"]').should('have.length.at.least', 1).each(($a) => cy.request($a.attr('href')).its('body').should('include', 'block-loop-item'))
    cy.visit('/artists/')
    cy.contains('a[href*="/artist/"]', 'KLAN')
  })

  it('links every album and playlist to tracks that exist', () => {
    cy.allPosts().then((posts) => {
      const ids = new Set(posts.map((p) => String(p.id)))
      posts.filter((p) => p.meta.type === 'album' || p.meta.type === 'playlist').forEach((album) => {
        const tracks = String(album.meta.post).split(',').filter(Boolean)
        expect(tracks, album.title.rendered).to.not.be.empty
        tracks.forEach((id) => expect(ids.has(id), `${album.title.rendered} track ${id}`).to.equal(true))
      })
    })
  })
})
