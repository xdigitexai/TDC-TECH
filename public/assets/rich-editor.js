'use strict';
function tdcRichEditor(textarea){
  const wrapper=document.createElement('section'),toolbar=document.createElement('div'),editor=document.createElement('div');
  toolbar.style.cssText='display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px';
  editor.contentEditable='true';
  editor.setAttribute('role','textbox');
  editor.setAttribute('aria-label',textarea.getAttribute('aria-label')||'Rich text message');
  editor.className='input';
  editor.style.cssText='min-height:220px;white-space:pre-wrap;line-height:1.8';
  editor.textContent=textarea.value;

  function sync(){textarea.value=editor.innerHTML;}
  function editableRange(){
    const selection=getSelection();
    if(!selection.rangeCount)return null;
    const range=selection.getRangeAt(0);
    if(!editor.contains(range.commonAncestorContainer)||range.collapsed)return null;
    return range;
  }
  function wrapSelection(tag){
    const range=editableRange();
    if(!range)return;
    const format=document.createElement(tag);
    format.append(range.extractContents());
    range.insertNode(format);
    sync();
  }
  function command(cmd,value){
    editor.focus();
    try{document.execCommand(cmd,false,value||null);}catch(err){}
    sync();
  }

  const buttons=[
    ['Bold',()=>wrapSelection('strong')],
    ['Italic',()=>wrapSelection('em')],
    ['H3',()=>wrapSelection('h3')],
    ['Quote',()=>wrapSelection('blockquote')],
    ['• List',()=>command('insertUnorderedList')],
    ['1. List',()=>command('insertOrderedList')],
    ['Paragraph',()=>command('formatBlock','p')]
  ];
  for(const [label,handler] of buttons){
    const button=document.createElement('button');
    button.type='button';
    button.textContent=label;
    button.className='btn small';
    button.onmousedown=e=>e.preventDefault();
    button.onclick=handler;
    toolbar.append(button);
  }

  editor.oninput=sync;
  textarea.hidden=true;
  wrapper.append(toolbar,editor);
  textarea.after(wrapper);
  textarea.form?.addEventListener('reset',()=>setTimeout(()=>{editor.replaceChildren();},0));

  /* keeps the visual editor in step when a script sets textarea.value */
  editor.tdcSync=()=>{editor.innerHTML=textarea.value;};
  return editor;
}
