// stdout: hello

fn main() {
    let owned = String::from("hello");
    let s: &str = &*owned;
    println!("{}", s);
}
