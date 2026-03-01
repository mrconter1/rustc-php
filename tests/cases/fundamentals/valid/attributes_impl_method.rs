// exit: 0
// stdout: 42
struct S {}

impl S {
    #[inline]
    fn get(&self) -> i32 {
        42
    }
}

fn main() {
    let s = S {};
    println!("{}", s.get());
}
