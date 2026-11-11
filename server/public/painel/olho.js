/* Botão de olho nos campos de senha do painel: mostra ou esconde o que foi digitado. */
(function(){
  var olho='<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="risco" d="M4 4l16 16"/></svg>';
  document.querySelectorAll('input[type="password"]').forEach(function(i){
    var caixa=document.createElement('span');caixa.className='senha-box';
    i.parentNode.insertBefore(caixa,i);caixa.appendChild(i);
    var b=document.createElement('button');b.type='button';b.className='olho';
    b.setAttribute('aria-label','Mostrar senha');b.setAttribute('aria-pressed','false');b.innerHTML=olho;
    b.addEventListener('click',function(){
      var ver=i.type==='password';i.type=ver?'text':'password';
      b.setAttribute('aria-pressed',String(ver));b.setAttribute('aria-label',ver?'Esconder senha':'Mostrar senha');
    });
    caixa.appendChild(b);
    if(i.form)i.form.addEventListener('submit',function(){i.type='password'});
  });
})();
