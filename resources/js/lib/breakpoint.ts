import { onBeforeUnmount, ref, type Ref } from 'vue'

/** スマホとタブレットの境界（08 §2.4。tokens.css のメディアクエリと同じ値） */
export const BREAKPOINT_PX = 768

/** 幅 768px 以上なら true。画面の回転・幅の変更に追従する */
export function useIsTablet(): Ref<boolean> {
  const query = window.matchMedia(`(min-width: ${BREAKPOINT_PX}px)`)
  const isTablet = ref(query.matches)
  const onChange = (e: MediaQueryListEvent): void => {
    isTablet.value = e.matches
  }
  query.addEventListener('change', onChange)
  onBeforeUnmount(() => query.removeEventListener('change', onChange))
  return isTablet
}
