// stdout: hello world
// stdout: hello world
fn greet(name: &String) {
    println!("hello {}", name);
}

fn main() {
    let s = String::from("world");
    greet(&s);
    greet(&s);
}
